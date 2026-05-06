<?php

declare(strict_types=1);

namespace AIGateway\Bundle\DependencyInjection;

use AIGateway\Config\ModelRegistry;
use AIGateway\Core\Gateway;
use AIGateway\Core\GatewayInterface;
use AIGateway\Core\ProviderHttpClient;
use AIGateway\Pipeline\RetryConfig;
use AIGateway\Provider\Anthropic\AnthropicAdapter;
use AIGateway\Provider\OpenAI\OpenAIAdapter;
use AIGateway\Provider\ProviderAdapterInterface;

use function sprintf;

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

            match ($name) {
                'openai' => $container->register($adapterServiceId, OpenAIAdapter::class)
                    ->setArguments([
                        '$apiKey' => $providerConfig['api_key'] ?? '',
                        '$baseUrl' => $providerConfig['base_url'] ?? 'https://api.openai.com/v1',
                        '$organization' => $providerConfig['organization'] ?? null,
                        '$timeoutSeconds' => $providerConfig['timeout_seconds'] ?? 30,
                    ])
                    ->addTag('ai_gateway.provider', ['provider' => $name]),

                'anthropic' => $container->register($adapterServiceId, AnthropicAdapter::class)
                    ->setArguments([
                        '$apiKey' => $providerConfig['api_key'] ?? '',
                        '$baseUrl' => $providerConfig['base_url'] ?? 'https://api.anthropic.com/v1',
                        '$timeoutSeconds' => $providerConfig['timeout_seconds'] ?? 30,
                    ])
                    ->addTag('ai_gateway.provider', ['provider' => $name]),

                default => null,
            };
        }
    }

    private function registerProviderHttpClient(ContainerBuilder $container): void
    {
        $container
            ->autowire(ProviderHttpClient::class, ProviderHttpClient::class)
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
                '$providers' => $providerServices,
                '$pipelines' => '%ai_gateway.pipelines%',
                '$aliases' => '%ai_gateway.aliases%',
                '$defaultRetryConfig' => new Reference(RetryConfig::class),
                '$logger' => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);

        $container->setAlias(GatewayInterface::class, Gateway::class);
    }
}
