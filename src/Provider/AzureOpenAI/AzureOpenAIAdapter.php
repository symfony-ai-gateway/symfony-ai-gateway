<?php

declare(strict_types=1);

namespace AIGateway\Provider\AzureOpenAI;

use AIGateway\Core\Choice;
use AIGateway\Core\NormalizedRequest;
use AIGateway\Core\NormalizedResponse;
use AIGateway\Core\Usage;
use AIGateway\Provider\ProviderAdapterInterface;
use AIGateway\Provider\ProviderCapabilities;
use AIGateway\Provider\ProviderError;
use AIGateway\Provider\ProviderRequest;
use AIGateway\Provider\ProviderResponse;

use function in_array;
use function is_array;

use const JSON_THROW_ON_ERROR;

use JsonException;

use function sprintf;

final readonly class AzureOpenAIAdapter implements ProviderAdapterInterface
{
    public function __construct(
        private string $apiKey,
        private string $resourceName,
        private string $apiVersion = '2024-06-01',
        private int $timeoutSeconds = 30,
    ) {
    }

    public function getName(): string
    {
        return 'azure_openai';
    }

    public function translateRequest(NormalizedRequest $request): ProviderRequest
    {
        return new ProviderRequest(
            url: sprintf(
                'https://%s.openai.azure.com/openai/deployments/%s/chat/completions?api-version=%s',
                $this->resourceName,
                $request->model,
                $this->apiVersion,
            ),
            method: 'POST',
            headers: [
                'Content-Type' => 'application/json',
                'api-key' => $this->apiKey,
            ],
            body: json_encode($request->toArray(), JSON_THROW_ON_ERROR),
            timeoutSeconds: $this->timeoutSeconds,
        );
    }

    public function translateResponse(ProviderResponse $response, string $requestedModel): NormalizedResponse
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        $choices = [];
        foreach ($data['choices'] ?? [] as $choiceData) {
            if (is_array($choiceData)) {
                $choices[] = Choice::fromArray($choiceData);
            }
        }

        $usage = Usage::fromArray($data['usage'] ?? []);

        return new NormalizedResponse(
            id: $data['id'] ?? sprintf('azure-%s', bin2hex(random_bytes(12))),
            model: $data['model'] ?? $requestedModel,
            provider: $this->getName(),
            choices: $choices,
            usage: $usage,
            statusCode: $response->statusCode,
            systemFingerprint: $data['system_fingerprint'] ?? null,
        );
    }

    public function isRetryableError(int $statusCode, string $body): bool
    {
        return in_array($statusCode, [429, 500, 502, 503, 504], true);
    }

    public function parseError(int $statusCode, string $body): ProviderError
    {
        $retryable = $this->isRetryableError($statusCode, $body);

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $error = $data['error'] ?? $data;

            return new ProviderError(
                code: (string) ($error['code'] ?? (string) $statusCode),
                message: (string) ($error['message'] ?? 'Unknown Azure OpenAI error'),
                type: 'azure_error',
                retryable: $retryable,
            );
        } catch (JsonException) {
            return new ProviderError(
                code: (string) $statusCode,
                message: sprintf('Azure OpenAI returned HTTP %d', $statusCode),
                type: 'http_error',
                retryable: $retryable,
            );
        }
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
