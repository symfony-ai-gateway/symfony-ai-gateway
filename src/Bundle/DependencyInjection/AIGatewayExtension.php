<?php

declare(strict_types=1);

namespace AIGateway\Bundle\DependencyInjection;

use AIGateway\Auth\ApiKeyAuthenticator;
use AIGateway\Auth\AuthEnforcer;
use AIGateway\Auth\Store\DbalKeyStore;
use AIGateway\Auth\Store\KeyStoreInterface;
use AIGateway\Auth\Store\SlidingWindowKeyRateLimiter;
use AIGateway\Bundle\EventSubscriber\ConfigSchemaInitSubscriber;
use AIGateway\Bundle\EventSubscriber\DashboardAuthSubscriber;
use AIGateway\Bundle\EventSubscriber\JsonExceptionSubscriber;
use AIGateway\Bundle\Routing\AIGatewayRouteLoader;
use AIGateway\Config\ConfigStore;
use AIGateway\Config\DynamicProviderFactory;
use AIGateway\Config\ModelRegistry;
use AIGateway\Controller\ChatController;
use AIGateway\Controller\DashboardController;
use AIGateway\Core\Gateway;
use AIGateway\Core\GatewayInterface;
use AIGateway\Core\ProviderHttpClient;
use AIGateway\Core\StreamProxy;
use AIGateway\Logging\RequestLogger;
use AIGateway\Metrics\PrometheusMetrics;
use AIGateway\Pipeline\RetryConfig;
use AIGateway\Provider\ProviderAdapterInterface;
use AIGateway\Provider\ProviderCapabilities;
use AIGateway\Provider\SymfonyAi\SymfonyAiProviderAdapter;

use function dirname;
use function is_string;
use function sprintf;

use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Gemini\Factory as GeminiFactory;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog as OpenAiModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

final class AIGatewayExtension extends ConfigurableExtension implements PrependExtensionInterface
{
    public function getAlias(): string
    {
        return 'ai_gateway';
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('twig', [
            'paths' => [
                dirname(__DIR__).'/Resources/views' => 'AIGateway',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $mergedConfig
     */
    protected function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $container->setParameter('ai_gateway.default_model', $mergedConfig['default_model'] ?? null);

        $this->registerModelRegistry($mergedConfig, $container);
        $this->registerProviders($mergedConfig, $container);
        $this->registerProviderHttpClient($container);
        $this->registerPipelines($mergedConfig, $container);
        $this->registerRetryConfig($mergedConfig, $container);
        $this->registerAuth($container);
        $this->registerControllers($mergedConfig, $container);
        $this->registerGateway($container);
        $this->registerRouteLoader($mergedConfig, $container);
        $this->registerConfigStore($container);
        $this->registerEventSubscribers($mergedConfig, $container);

        $container
            ->registerForAutoconfiguration(ProviderAdapterInterface::class)
            ->addTag('ai_gateway.provider');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerModelRegistry(array $config, ContainerBuilder $container): void
    {
        $models = $config['models'] ?? [];

        $registryDefinition = $container
            ->autowire(ModelRegistry::class, ModelRegistry::class);

        $registryArgs = [];
        foreach ($models as $alias => $modelConfig) {
            $registryArgs[$alias] = [
                'provider' => $modelConfig['provider'],
                'model' => $modelConfig['model'],
                'pricing' => [
                    'input' => $modelConfig['pricing']['input'] ?? 0.0,
                    'output' => $modelConfig['pricing']['output'] ?? 0.0,
                ],
                'max_tokens' => $modelConfig['max_tokens'] ?? 128000,
            ];
        }

        $registryDefinition->setArguments([
            '$config' => $registryArgs,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerProviders(array $config, ContainerBuilder $container): void
    {
        $providers = $config['providers'] ?? [];

        if ([] === $providers) {
            return;
        }

        $modelsByProvider = $this->collectModelsByProvider($config['models'] ?? []);

        foreach ($providers as $name => $providerConfig) {
            $adapterServiceId = sprintf('ai_gateway.provider.%s', $name);
            $platformServiceId = sprintf('ai_gateway.platform.%s', $name);
            $format = $providerConfig['format'] ?? 'openai';
            $apiKey = $providerConfig['api_key'] ?? '';
            $baseUrl = $providerConfig['base_url'] ?? null;

            $this->registerSymfonyAiPlatform(
                container: $container,
                platformServiceId: $platformServiceId,
                providerName: $name,
                format: $format,
                apiKey: $apiKey,
                baseUrl: $baseUrl,
                providerConfig: $providerConfig,
                modelNames: $modelsByProvider[$name] ?? [],
            );

            $capabilitiesServiceId = sprintf('ai_gateway.provider.%s.capabilities', $name);

            $container->register($capabilitiesServiceId, ProviderCapabilities::class)
                ->setArguments([
                    '$streaming' => $providerConfig['streaming'] ?? true,
                    '$vision' => $providerConfig['vision'] ?? false,
                    '$functionCalling' => $providerConfig['function_calling'] ?? true,
                    '$maxTokensPerRequest' => $providerConfig['max_tokens_per_request'] ?? 128000,
                ]);

            $container->register($adapterServiceId, SymfonyAiProviderAdapter::class)
                ->setArguments([
                    '$name' => $name,
                    '$platform' => new Reference($platformServiceId),
                    '$capabilities' => new Reference($capabilitiesServiceId),
                ])
                ->addTag('ai_gateway.provider', ['provider' => $name]);
        }
    }

    /**
     * @param array<string, mixed> $providerConfig
     * @param list<string>         $modelNames
     */
    private function registerSymfonyAiPlatform(
        ContainerBuilder $container,
        string $platformServiceId,
        string $providerName,
        string $format,
        string $apiKey,
        string|null $baseUrl,
        array $providerConfig,
        array $modelNames = [],
    ): void {
        $definition = $container->register($platformServiceId, \Symfony\AI\Platform\Platform::class);

        match ($format) {
            'openai' => $this->registerOpenAiPlatform($definition, $apiKey, $providerName, $container, $modelNames, $baseUrl, $providerConfig['completions_path'] ?? '/v1/chat/completions'),
            'anthropic' => $definition
                ->setFactory([AnthropicFactory::class, 'createPlatform'])
                ->setArguments([
                    '$apiKey' => $apiKey,
                    '$httpClient' => new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    '$name' => $providerName,
                ]),
            'gemini' => $definition
                ->setFactory([GeminiFactory::class, 'createPlatform'])
                ->setArguments([
                    '$apiKey' => $apiKey,
                    '$httpClient' => new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    '$name' => $providerName,
                ]),
            'ollama' => $definition
                ->setFactory([OllamaFactory::class, 'createPlatform'])
                ->setArguments([
                    '$endpoint' => $baseUrl ?? 'http://localhost:11434',
                    '$apiKey' => '' !== $apiKey ? $apiKey : null,
                    '$httpClient' => new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    '$name' => $providerName,
                ]),
            'azure' => $definition
                ->setFactory([GenericFactory::class, 'createPlatform'])
                ->setArguments([
                    '$baseUrl' => $baseUrl ?? 'https://YOUR_RESOURCE.openai.azure.com',
                    '$apiKey' => $apiKey,
                    '$httpClient' => new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    '$supportsCompletions' => true,
                    '$supportsEmbeddings' => false,
                    '$completionsPath' => $providerConfig['completions_path'] ?? '/openai/deployments/gpt-4/chat/completions?api-version=2024-02-15-preview',
                    '$name' => $providerName,
                ]),
            default => throw new InvalidConfigurationException(sprintf('Unknown provider format "%s".', $format)),
        };
    }

    /**
     * @param list<string> $modelNames
     */
    private function registerOpenAiPlatform(
        \Symfony\Component\DependencyInjection\Definition $definition,
        string $apiKey,
        string $providerName,
        ContainerBuilder $container,
        array $modelNames,
        string|null $baseUrl,
        string $completionsPath = '/v1/chat/completions',
    ): void {
        if (null !== $baseUrl) {
            $definition
                ->setFactory([GenericFactory::class, 'createPlatform'])
                ->setArguments([
                    '$baseUrl' => $baseUrl,
                    '$apiKey' => $apiKey,
                    '$httpClient' => new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    '$supportsCompletions' => true,
                    '$supportsEmbeddings' => false,
                    '$completionsPath' => $completionsPath,
                    '$name' => $providerName,
                ]);

            return;
        }

        $additionalModels = [];
        foreach ($modelNames as $modelName) {
            $additionalModels[$modelName] = [
                'class' => Gpt::class,
                'capabilities' => [
                    Capability::INPUT_MESSAGES,
                    Capability::OUTPUT_TEXT,
                    Capability::OUTPUT_STREAMING,
                    Capability::TOOL_CALLING,
                ],
            ];
        }

        $modelCatalogServiceId = sprintf('ai_gateway.model_catalog.%s', $providerName);
        $container->register($modelCatalogServiceId, OpenAiModelCatalog::class)
            ->setArguments([
                '$additionalModels' => $additionalModels,
            ]);

        $definition
            ->setFactory([OpenAiFactory::class, 'createPlatform'])
            ->setArguments([
                '$apiKey' => $apiKey,
                '$httpClient' => new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$modelCatalog' => new Reference($modelCatalogServiceId),
                '$name' => $providerName,
            ]);
    }

    /**
     * @param array<string, mixed> $modelsConfig
     *
     * @return array<string, list<string>>
     */
    private function collectModelsByProvider(array $modelsConfig): array
    {
        $byProvider = [];
        foreach ($modelsConfig as $alias => $modelConfig) {
            $provider = $modelConfig['provider'] ?? '';
            if ('' === $provider) {
                continue;
            }
            $byProvider[$provider][] = $modelConfig['model'] ?? $alias;
        }

        return $byProvider;
    }

    private function registerProviderHttpClient(ContainerBuilder $container): void
    {
        $container
            ->autowire(ProviderHttpClient::class, ProviderHttpClient::class)
            ->setArguments([
                '$httpClient' => new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$logger' => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);

        $container
            ->autowire(StreamProxy::class, StreamProxy::class)
            ->setArguments([
                '$httpClient' => new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$logger' => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerPipelines(array $config, ContainerBuilder $container): void
    {
        $container->setParameter('ai_gateway.pipelines', $config['pipelines'] ?? []);

        $container->setParameter('ai_gateway.aliases', $config['aliases'] ?? []);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerRetryConfig(array $config, ContainerBuilder $container): void
    {
        $retryConfig = $config['retry'] ?? [];

        $container->register(RetryConfig::class, RetryConfig::class)
            ->setArguments([
                '$maxAttempts' => $retryConfig['max_attempts'] ?? 2,
                '$delayMs' => $retryConfig['delay_ms'] ?? 1000,
                '$backoff' => $retryConfig['backoff'] ?? 'exponential',
            ]);
    }

    private function registerGateway(ContainerBuilder $container): void
    {
        $providerServices = [];

        foreach ($container->findTaggedServiceIds('ai_gateway.provider') as $id => $tags) {
            foreach ($tags as $tag) {
                $providerName = $tag['provider'] ?? throw new InvalidConfigurationException(sprintf('Service "%s" tagged "ai_gateway.provider" must have a "provider" attribute.', $id));
                $providerServices[$providerName] = new Reference($id);
            }
        }

        $gatewayDef = $container
            ->autowire(Gateway::class, Gateway::class)
            ->setArguments([
                '$modelRegistry' => new Reference(ModelRegistry::class),
                '$httpClient' => new Reference(ProviderHttpClient::class),
                '$streamProxy' => new Reference(StreamProxy::class),
                '$providers' => $providerServices,
                '$pipelines' => '%ai_gateway.pipelines%',
                '$aliases' => '%ai_gateway.aliases%',
                '$defaultRetryConfig' => new Reference(RetryConfig::class),
                '$logger' => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$configStore' => new Reference(ConfigStore::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$dynamicFactory' => new Reference(DynamicProviderFactory::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);

        if ($container->hasDefinition(AuthEnforcer::class)) {
            $gatewayDef->setArgument('$authEnforcer', new Reference(AuthEnforcer::class));
        }

        $container->setAlias(GatewayInterface::class, Gateway::class);
    }

    private function registerAuth(ContainerBuilder $container): void
    {
        $container->register(SlidingWindowKeyRateLimiter::class, SlidingWindowKeyRateLimiter::class)
            ->setArguments([
                '$connection' => new Reference('doctrine.dbal.default_connection', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);

        $container->register(DbalKeyStore::class, DbalKeyStore::class)
            ->setArguments([
                '$connection' => new Reference('doctrine.dbal.default_connection'),
            ]);

        $container->setAlias(KeyStoreInterface::class, DbalKeyStore::class);

        $container->register(ApiKeyAuthenticator::class, ApiKeyAuthenticator::class)
            ->setArguments([
                '$keyStore' => new Reference(KeyStoreInterface::class),
            ]);

        $container->register(AuthEnforcer::class, AuthEnforcer::class)
            ->setArguments([
                '$keyStore' => new Reference(KeyStoreInterface::class),
                '$rateLimiter' => new Reference(SlidingWindowKeyRateLimiter::class),
            ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerControllers(array $config, ContainerBuilder $container): void
    {
        $container->register(ChatController::class, ChatController::class)
            ->setArguments([
                '$gateway' => new Reference(GatewayInterface::class),
                '$configStore' => new Reference(ConfigStore::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$requestLogger' => new Reference('AIGateway\Logging\RequestLogger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$metrics' => new Reference('AIGateway\Metrics\PrometheusMetrics', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$authenticator' => new Reference(ApiKeyAuthenticator::class),
                '$authRequired' => true,
            ])
            ->addTag('controller.service_arguments');

        $container->register(DashboardController::class, DashboardController::class)
            ->setArguments([
                '$twig' => new Reference('twig'),
                '$keyStore' => new Reference(KeyStoreInterface::class),
                '$requestLogger' => new Reference('AIGateway\Logging\RequestLogger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$configStore' => new Reference(ConfigStore::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag('controller.service_arguments');
    }

    private function registerConfigStore(ContainerBuilder $container): void
    {
        $container->register(ConfigStore::class, ConfigStore::class)
            ->setArguments([
                '$connection' => new Reference('doctrine.dbal.default_connection', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);

        $container->register(DynamicProviderFactory::class, DynamicProviderFactory::class)
            ->setArguments([
                '$httpClient' => new Reference('http_client'),
            ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerEventSubscribers(array $config, ContainerBuilder $container): void
    {
        $container->autowire(RequestLogger::class, RequestLogger::class);
        $container->autowire(PrometheusMetrics::class, PrometheusMetrics::class);

        $container->register(JsonExceptionSubscriber::class, JsonExceptionSubscriber::class)
            ->addTag('kernel.event_subscriber');

        $container->register(ConfigSchemaInitSubscriber::class, ConfigSchemaInitSubscriber::class)
            ->setArguments([
                '$configStore' => new Reference(ConfigStore::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag('kernel.event_subscriber');

        /** @var array<string, mixed> $dashboardConfig */
        $dashboardConfig = $config['dashboard'] ?? [];
        $dashboardToken = isset($dashboardConfig['token']) && is_string($dashboardConfig['token']) ? $dashboardConfig['token'] : null;

        if (($dashboardConfig['tokenRequired'] ?? false) && null !== $dashboardToken && '' !== $dashboardToken) {
            $container->register(DashboardAuthSubscriber::class, DashboardAuthSubscriber::class)
                ->setArguments([
                    '$dashboardToken' => $dashboardToken,
                    '$routePrefix' => $config['routes']['prefix'] ?? '',
                ])
                ->addTag('kernel.event_subscriber');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerRouteLoader(array $config, ContainerBuilder $container): void
    {
        $routesConfig = $config['routes'] ?? [];

        $container->register(AIGatewayRouteLoader::class, AIGatewayRouteLoader::class)
            ->setArguments([
                '$prefix' => $routesConfig['prefix'] ?? '',
                '$enabled' => $routesConfig['enabled'] ?? true,
            ])
            ->addTag('routing.loader');
    }
}
