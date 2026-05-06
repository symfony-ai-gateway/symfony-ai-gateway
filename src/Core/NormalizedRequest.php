<?php

declare(strict_types=1);

namespace PhiGateway\Core;

use PhiGateway\Exception\GatewayException;

/**
 * Immutable, normalized representation of an LLM chat request.
 *
 * @see https://platform.openai.com/docs/api-reference/chat/create
 */
final readonly class NormalizedRequest
{
    /**
     * @param list<Message> $messages
     * @param list<string>|null $stop
     * @param list<array<string, mixed>>|null $tools
     * @param string|array<string, mixed>|null $toolChoice
     * @param string|array<string, mixed>|null $responseFormat
     */
    public function __construct(
        public string $model,
        public array $messages,
        public float $temperature = 1.0,
        public ?int $maxTokens = null,
        public ?float $topP = null,
        public ?float $frequencyPenalty = null,
        public ?float $presencePenalty = null,
        public ?array $stop = null,
        public bool $stream = false,
        public ?array $tools = null,
        public string|array|null $toolChoice = null,
        public string|array|null $responseFormat = null,
        public ?int $seed = null,
        public ?string $user = null,
    ) {
    }

    /**
     * Factory from a raw payload array (OpenAI-compatible format).
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $model = $payload['model'] ?? null;
        if (!\is_string($model) || $model === '') {
            throw GatewayException::invalidRequest('"model" is required and must be a non-empty string.');
        }

        $rawMessages = $payload['messages'] ?? [];
        if (!\is_array($rawMessages) || $rawMessages === []) {
            throw GatewayException::invalidRequest('"messages" is required and must be a non-empty array.');
        }

        $messages = [];
        /** @var mixed $rawMessage */
        foreach ($rawMessages as $rawMessage) {
            if (is_array($rawMessage) && isset($rawMessage['role']) && is_string($rawMessage['role'])) {
                $messages[] = Message::fromArray($rawMessage);
            }
        }

        if ($messages === []) {
            throw GatewayException::invalidRequest('"messages" must contain at least one valid message.');
        }

        return new self(
            model: $model,
            messages: $messages,
            temperature: \is_float($payload['temperature'] ?? null) || \is_int($payload['temperature'] ?? null)
                ? (float) $payload['temperature'] : 1.0,
            maxTokens: \is_int($payload['max_tokens'] ?? null) ? $payload['max_tokens'] : null,
            topP: \is_float($payload['top_p'] ?? null) || \is_int($payload['top_p'] ?? null)
                ? (float) $payload['top_p'] : null,
            frequencyPenalty: \is_float($payload['frequency_penalty'] ?? null) || \is_int($payload['frequency_penalty'] ?? null)
                ? (float) $payload['frequency_penalty'] : null,
            presencePenalty: \is_float($payload['presence_penalty'] ?? null) || \is_int($payload['presence_penalty'] ?? null)
                ? (float) $payload['presence_penalty'] : null,
            stop: \is_array($payload['stop'] ?? null) ? $payload['stop'] : null,
            stream: \is_bool($payload['stream'] ?? null) ? $payload['stream'] : false,
            tools: \is_array($payload['tools'] ?? null) ? $payload['tools'] : null,
            toolChoice: $payload['tool_choice'] ?? null,
            responseFormat: $payload['response_format'] ?? null,
            seed: \is_int($payload['seed'] ?? null) ? $payload['seed'] : null,
            user: \is_string($payload['user'] ?? null) ? $payload['user'] : null,
        );
    }

    /**
     * Deterministic hash for cache keying (excludes streaming and user fields).
     */
    public function toHash(): string
    {
        $parts = [
            $this->model,
            array_map(static fn (Message $m): array => $m->toArray(), $this->messages),
            $this->temperature,
            $this->maxTokens,
            $this->topP,
            $this->frequencyPenalty,
            $this->presencePenalty,
            $this->stop,
            $this->seed,
        ];

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /**
     * Convert to OpenAI-compatible array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'model' => $this->model,
            'messages' => array_map(static fn (Message $m): array => $m->toArray(), $this->messages),
        ];

        if ($this->temperature !== 1.0) {
            $result['temperature'] = $this->temperature;
        }

        if ($this->maxTokens !== null) {
            $result['max_tokens'] = $this->maxTokens;
        }

        if ($this->topP !== null) {
            $result['top_p'] = $this->topP;
        }

        if ($this->frequencyPenalty !== null) {
            $result['frequency_penalty'] = $this->frequencyPenalty;
        }

        if ($this->presencePenalty !== null) {
            $result['presence_penalty'] = $this->presencePenalty;
        }

        if ($this->stop !== null) {
            $result['stop'] = $this->stop;
        }

        if ($this->stream) {
            $result['stream'] = true;
        }

        if ($this->tools !== null) {
            $result['tools'] = $this->tools;
        }

        if ($this->toolChoice !== null) {
            $result['tool_choice'] = $this->toolChoice;
        }

        if ($this->responseFormat !== null) {
            $result['response_format'] = $this->responseFormat;
        }

        if ($this->seed !== null) {
            $result['seed'] = $this->seed;
        }

        if ($this->user !== null) {
            $result['user'] = $this->user;
        }

        return $result;
    }
}
