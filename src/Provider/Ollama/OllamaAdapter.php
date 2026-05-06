<?php

declare(strict_types=1);

namespace AIGateway\Provider\Ollama;

use AIGateway\Core\Choice;
use AIGateway\Core\Message;
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

use function sprintf;

final class OllamaAdapter implements ProviderAdapterInterface
{
    public function __construct(
        private readonly string $baseUrl = 'http://localhost:11434',
        private readonly int $timeoutSeconds = 120,
    ) {
    }

    public function getName(): string
    {
        return 'ollama';
    }

    public function translateRequest(NormalizedRequest $request): ProviderRequest
    {
        $body = [
            'model' => $request->model,
            'messages' => array_map(static fn (Message $m): array => $m->toArray(), $request->messages),
            'stream' => $request->stream,
        ];

        if (null !== $request->temperature && 1.0 !== $request->temperature) {
            $body['options']['temperature'] = $request->temperature;
        }

        if (null !== $request->topP) {
            $body['options']['top_p'] = $request->topP;
        }

        if (null !== $request->stop) {
            $body['options']['stop'] = $request->stop;
        }

        if (null !== $request->tools) {
            $body['tools'] = $request->tools;
        }

        if (null !== $request->maxTokens) {
            $body['options']['num_predict'] = $request->maxTokens;
        }

        return new ProviderRequest(
            url: sprintf('%s/api/chat', rtrim($this->baseUrl, '/')),
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body, JSON_THROW_ON_ERROR),
            timeoutSeconds: $this->timeoutSeconds,
        );
    }

    public function translateResponse(ProviderResponse $response, string $requestedModel): NormalizedResponse
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        $messageData = $data['message'] ?? [];
        if (!is_array($messageData) || [] === $messageData) {
            $messageData = ['role' => 'assistant', 'content' => ''];
        }
        /** @var array{role: string, content?: string|null} $messageData */
        $message = Message::fromArray(array_merge(['role' => 'assistant', 'content' => ''], $messageData));

        $choice = new Choice(
            index: 0,
            message: $message,
            finishReason: ($data['done'] ?? false) ? 'stop' : null,
        );

        $promptTokens = $data['prompt_eval_count'] ?? 0;
        $completionTokens = $data['eval_count'] ?? 0;
        $totalTokens = $promptTokens + $completionTokens;

        if (0 === $totalTokens) {
            $content = $message->content;
            $promptTokens = $this->estimateTokens([]);
            $completionTokens = $this->estimateTokens([$content]);
            $totalTokens = $promptTokens + $completionTokens;
        }

        $usage = new Usage((int) $promptTokens, (int) $completionTokens, (int) $totalTokens);

        return new NormalizedResponse(
            id: sprintf('ollama-%s', bin2hex(random_bytes(8))),
            model: $data['model'] ?? $requestedModel,
            provider: $this->getName(),
            choices: [$choice],
            usage: $usage,
            statusCode: $response->statusCode,
        );
    }

    public function isRetryableError(int $statusCode, string $body): bool
    {
        return in_array($statusCode, [429, 500, 502, 503, 504], true);
    }

    public function parseError(int $statusCode, string $body): ProviderError
    {
        $retryable = $this->isRetryableError($statusCode, $body);

        return new ProviderError(
            code: (string) $statusCode,
            message: sprintf('Ollama returned HTTP %d: %s', $statusCode, $body),
            type: 'ollama_error',
            retryable: $retryable,
        );
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

    /**
     * Rough token estimation: ~4 characters per token for English text.
     *
     * @param list<string> $texts
     */
    private function estimateTokens(array $texts): int
    {
        $totalChars = (int) array_sum(array_map('strlen', $texts));

        return (int) ceil($totalChars / 4);
    }
}
