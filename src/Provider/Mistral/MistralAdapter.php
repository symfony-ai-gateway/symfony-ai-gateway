<?php

declare(strict_types=1);

namespace AIGateway\Provider\Mistral;

use AIGateway\Provider\OpenAICompatibleAdapter;
use AIGateway\Provider\ProviderCapabilities;

final readonly class MistralAdapter extends OpenAICompatibleAdapter
{
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.mistral.ai/v1',
        int $timeoutSeconds = 30,
    ) {
        parent::__construct($apiKey, $baseUrl, $timeoutSeconds);
    }

    public function getName(): string
    {
        return 'mistral';
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
