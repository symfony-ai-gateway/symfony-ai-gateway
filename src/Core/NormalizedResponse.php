<?php

declare(strict_types=1);

namespace AIGateway\Core;

/**
 * Immutable, normalized LLM response in OpenAI-compatible format.
 */
final readonly class NormalizedResponse
{
    /**
     * @param list<Choice> $choices
     */
    public function __construct(
        public string $id,
        public string $model,
        public string $provider,
        public array $choices,
        public Usage $usage,
        public int $statusCode = 200,
        public string|null $systemFingerprint = null,
        public string|null $fallbackFrom = null,
        public float $durationMs = 0.0,
        public bool $cacheHit = false,
        public float $costUsd = 0.0,
    ) {
    }

    /**
     * Convenience: the main text content of the first choice.
     */
    public function getContent(): string
    {
        return $this->choices[0]?->message->content ?? '';
    }

    /**
     * @return array<string, mixed> OpenAI-compatible response format
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $this->model,
            'provider' => $this->provider,
            'choices' => array_map(static fn (Choice $c): array => $c->toArray(), $this->choices),
            'usage' => $this->usage->toArray(),
            'system_fingerprint' => $this->systemFingerprint,
        ];
    }
}
