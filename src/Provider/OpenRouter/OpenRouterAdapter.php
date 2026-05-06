<?php

declare(strict_types=1);

namespace AIGateway\Provider\OpenRouter;

use AIGateway\Provider\OpenAICompatibleAdapter;
use AIGateway\Provider\ProviderCapabilities;

final readonly class OpenRouterAdapter extends OpenAICompatibleAdapter
{
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://openrouter.ai/api/v1',
        int $timeoutSeconds = 30,
    ) {
        parent::__construct($apiKey, $baseUrl, $timeoutSeconds);
    }

    public function getName(): string
    {
        return 'openrouter';
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            streaming: true,
            vision: true,
            functionCalling: true,
            maxTokensPerRequest: 128000,
        );
    }
}
