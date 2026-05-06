<?php

declare(strict_types=1);

namespace AIGateway\Bundle\DependencyInjection;

use AIGateway\Config\ModelRegistry;
use AIGateway\Core\Gateway;
use AIGateway\Core\GatewayInterface;
use AIGateway\Core\ProviderHttpClient;
use AIGateway\Core\StreamProxy;
use AIGateway\Pipeline\RetryConfig;
use AIGateway\Provider\ProviderAdapterInterface;
use AIGateway\Provider\ProviderCapabilities;
use AIGateway\Provider\SymfonyAi\SymfonyAiProviderAdapter;

use function sprintf;

use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Gemini\Factory as GeminiFactory;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

final class AIGatewayExtension extends ConfigurableExtension
{
    public function getAlias(): string
    {
        return 'ai_gateway';
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
        $this->registerGateway($container);

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

        if ([] === $models) {
            throw new InvalidConfigurationException('At least one model must be configured under "ai_gateway.models".');
        }

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

        $container->setAlias(ModelRegistry::class, ModelRegistry::class);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerProviders(array $config, ContainerBuilder $container): void
    {
        $providers = $config['providers'] ?? [];

        if ([] === $providers) {
            throw new InvalidConfigurationException('At least one provider must be configured under "ai_gateway.providers".');
        }

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
            );

            $capabilities = new ProviderCapabilities(
                streaming: $providerConfig['streaming'] ?? true,
                vision: $providerConfig['vision'] ?? false,
                functionCalling: $providerConfig['function_calling'] ?? true,
                maxTokensPerRequest: $providerConfig['max_tokens_per_request'] ?? 128000,
            );

            $container->register($adapterServiceId, SymfonyAiProviderAdapter::class)
                ->setArguments([
                    '$name' => $name,
                    '$platform' => new Reference($platformServiceId),
                    '$capabilities' => $capabilities,
                ])
                ->addTag('ai_gateway.provider', ['provider' => $name]);
        }
    }

    /**
     * @param array<string, mixed> $providerConfig
     */
    private function registerSymfonyAiPlatform(
        ContainerBuilder $container,
        string $platformServiceId,
        string $providerName,
        string $format,
        string $apiKey,
        string|null $baseUrl,
        array $providerConfig,
    ): void {
        $definition = $container->register($platformServiceId);

        match ($format) {
            'openai' => $definition
                ->setFactory([OpenAiFactory::class, 'createPlatform'])
                ->setArguments([
                    '$apiKey' => $apiKey,
                    '$httpClient' => new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    '$name' => $providerName,
                ]),
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
        $pipelines = [];
        foreach ($config['pipelines'] ?? [] as $name => $pipelineConfig) {
            $pipelines[$name] = $pipelineConfig['models'] ?? [];
        }

        $container->setParameter('ai_gateway.pipelines', $pipelines);

        $aliases = [];
        foreach ($config['aliases'] ?? [] as $alias => $target) {
            $aliases[$alias] = $target;
        }

        $container->setParameter('ai_gateway.aliases', $aliases);
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

        $container
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
            ]);

        $container->setAlias(GatewayInterface::class, Gateway::class);
    }
}
