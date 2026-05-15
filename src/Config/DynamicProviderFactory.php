<?php

declare(strict_types=1);

namespace AIGateway\Config;

use AIGateway\Provider\ProviderCapabilities;
use AIGateway\Provider\SymfonyAi\SymfonyAiProviderAdapter;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DynamicProviderFactory
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param non-empty-string                                                                        $name
     * @param array{format: string, api_key: string, base_url: string|null, completions_path: string} $config
     */
    public function createAdapter(string $name, array $config): SymfonyAiProviderAdapter
    {
        $format = $config['format'] ?? 'openai';
        $apiKey = $config['api_key'] ?? '';
        $baseUrl = $config['base_url'] ?? null;
        $completionsPath = $config['completions_path'] ?? '/v1/chat/completions';

        $platform = match ($format) {
            'anthropic' => AnthropicFactory::createPlatform(
                apiKey: $apiKey,
                httpClient: $this->httpClient,
                name: $name,
            ),
            default => GenericFactory::createPlatform(
                baseUrl: $baseUrl ?? 'https://api.openai.com/v1',
                apiKey: $apiKey,
                httpClient: $this->httpClient,
                supportsCompletions: true,
                supportsEmbeddings: false,
                completionsPath: $completionsPath,
                name: $name,
            ),
        };

        return new SymfonyAiProviderAdapter(
            name: $name,
            platform: $platform,
            capabilities: new ProviderCapabilities(streaming: true, functionCalling: true),
        );
    }
}
