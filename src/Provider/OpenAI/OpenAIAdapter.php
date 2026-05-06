<?php

declare(strict_types=1);

namespace AIGateway\Provider\OpenAI;

use AIGateway\Core\Choice;
use AIGateway\Core\NormalizedRequest;
use AIGateway\Core\NormalizedResponse;
use AIGateway\Core\NormalizedStreamChunk;
use AIGateway\Core\Usage;
use AIGateway\Provider\ProviderCapabilities;
use AIGateway\Provider\ProviderError;
use AIGateway\Provider\ProviderRequest;
use AIGateway\Provider\ProviderResponse;
use AIGateway\Provider\StreamingProviderAdapterInterface;

use function in_array;

use const JSON_THROW_ON_ERROR;

use JsonException;

use function sprintf;

final readonly class OpenAIAdapter implements StreamingProviderAdapterInterface
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

    public function translateStreamChunk(string $rawChunk, string $requestedModel): NormalizedStreamChunk|null
    {
        $line = trim($rawChunk);

        if (!str_starts_with($line, 'data: ')) {
            return null;
        }

        $json = trim(substr($line, 6));

        if ('' === $json || '[DONE]' === $json) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $id = $data['id'] ?? sprintf('chatcmpl-%s', bin2hex(random_bytes(8)));
        $model = $data['model'] ?? $requestedModel;

        $choice = $data['choices'][0] ?? [];
        $delta = $choice['delta']['content'] ?? '';
        $finishReason = $choice['finish_reason'] ?? null;

        $usage = null;
        if (isset($data['usage'])) {
            $usage = Usage::fromArray($data['usage']);
        }

        if (null !== $finishReason && '' === $delta) {
            return new NormalizedStreamChunk(
                id: $id,
                model: $model,
                provider: $this->getName(),
                delta: '',
                finishReason: $finishReason,
                usage: $usage,
            );
        }

        return new NormalizedStreamChunk(
            id: $id,
            model: $model,
            provider: $this->getName(),
            delta: $delta,
            finishReason: null,
            usage: $usage,
        );
    }

    public function isStreamDone(string $rawChunk): bool
    {
        $line = trim($rawChunk);

        return str_ends_with($line, 'data: [DONE]');
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
