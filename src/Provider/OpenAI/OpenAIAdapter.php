<?php

declare(strict_types=1);

namespace PhiGateway\Provider\OpenAI;

use function in_array;

use const JSON_THROW_ON_ERROR;

use JsonException;
use PhiGateway\Core\Choice;
use PhiGateway\Core\NormalizedRequest;
use PhiGateway\Core\NormalizedResponse;
use PhiGateway\Core\Usage;
use PhiGateway\Provider\ProviderAdapterInterface;
use PhiGateway\Provider\ProviderCapabilities;
use PhiGateway\Provider\ProviderError;
use PhiGateway\Provider\ProviderRequest;
use PhiGateway\Provider\ProviderResponse;

use function sprintf;

final readonly class OpenAIAdapter implements ProviderAdapterInterface
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function __construct(
        private string $apiKey,
        private string $baseUrl = self::DEFAULT_BASE_URL,
        private string|null $organization = null,
        private int $timeoutSeconds = 30,
    ) {
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function translateRequest(NormalizedRequest $request): ProviderRequest
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => sprintf('Bearer %s', $this->apiKey),
        ];

        if (null !== $this->organization) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        return new ProviderRequest(
            url: sprintf('%s/chat/completions', rtrim($this->baseUrl, '/')),
            method: 'POST',
            headers: $headers,
            body: json_encode($request->toArray(), JSON_THROW_ON_ERROR),
            timeoutSeconds: $this->timeoutSeconds,
        );
    }

    public function translateResponse(ProviderResponse $response, string $requestedModel): NormalizedResponse
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        $choices = array_map(
            static fn (array $choice): Choice => Choice::fromArray($choice),
            $data['choices'] ?? [],
        );

        $usage = Usage::fromArray($data['usage'] ?? []);

        return new NormalizedResponse(
            id: $data['id'] ?? sprintf('chatcmpl-%s', bin2hex(random_bytes(12))),
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
                message: (string) ($error['message'] ?? 'Unknown OpenAI error'),
                type: (string) ($error['type'] ?? 'openai_error'),
                retryable: $retryable,
            );
        } catch (JsonException) {
            return new ProviderError(
                code: (string) $statusCode,
                message: sprintf('OpenAI returned HTTP %d with non-JSON body', $statusCode),
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
            audio: true,
            embeddings: true,
            functionCalling: true,
            maxTokensPerRequest: 128000,
        );
    }
}
