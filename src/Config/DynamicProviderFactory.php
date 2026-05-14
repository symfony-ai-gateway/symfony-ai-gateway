<?php

declare(strict_types=1);

namespace AIGateway\Config;

use AIGateway\Provider\ProviderCapabilities;
use AIGateway\Provider\SymfonyAi\SymfonyAiProviderAdapter;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DynamicProviderFactory
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array{format: string, api_key: string, base_url: string|null, completions_path: string} $config
     */
    /**
     * @param non-empty-string $name
     * @param array{format: string, api_key: string, base_url: string|null, completions_path: string} $config
     */
    public function createAdapter(string $name, array $config): SymfonyAiProviderAdapter
    {
        $platform = GenericFactory::createPlatform(
            baseUrl: $config['base_url'] ?? 'https://api.openai.com/v1',
            apiKey: $config['api_key'],
            httpClient: $this->httpClient,
            supportsCompletions: true,
            supportsEmbeddings: false,
            completionsPath: $config['completions_path'] ?? '/v1/chat/completions',
            name: $name,
        );

        return new SymfonyAiProviderAdapter(
            name: $name,
            platform: $platform,
            capabilities: new ProviderCapabilities(streaming: true, functionCalling: true),
        );
    }
}
