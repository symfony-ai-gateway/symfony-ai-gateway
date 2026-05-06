<?php

declare(strict_types=1);

namespace AIGateway\Provider\DeepSeek;

use AIGateway\Provider\OpenAICompatibleAdapter;
use AIGateway\Provider\ProviderCapabilities;

final readonly class DeepSeekAdapter extends OpenAICompatibleAdapter
{
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.deepseek.com/v1',
        int $timeoutSeconds = 30,
    ) {
        parent::__construct($apiKey, $baseUrl, $timeoutSeconds);
    }

    public function getName(): string
    {
        return 'deepseek';
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            streaming: true,
            vision: false,
            functionCalling: true,
            maxTokensPerRequest: 128000,
        );
    }
}
