<?php

declare(strict_types=1);

namespace AIGateway\Core;

/**
 * A single chunk emitted during a streaming LLM response.
 */
final readonly class NormalizedStreamChunk
{
    public function __construct(
        public string $id,
        public string $model,
        public string $provider,
        public string $delta,
        public string|null $finishReason = null,
        public Usage|null $usage = null,
    ) {
    }

    /**
     * @return array<string, mixed> OpenAI-compatible streaming chunk format
     */
    public function toArray(): array
    {
        $choices = [
            [
                'index' => 0,
                'delta' => [] !== array_filter(['content' => $this->delta], static fn (string $v): bool => '' !== $v)
                    ? ['content' => $this->delta]
                    : [],
                'finish_reason' => $this->finishReason,
            ],
        ];

        if (null !== $this->finishReason) {
            $choices[0]['delta'] = [];
        }

        $result = [
            'id' => $this->id,
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $this->model,
            'provider' => $this->provider,
            'choices' => $choices,
        ];

        if (null !== $this->usage) {
            $result['usage'] = $this->usage->toArray();
        }

        return $result;
    }
}
