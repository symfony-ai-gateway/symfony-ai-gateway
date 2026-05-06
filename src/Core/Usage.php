<?php

declare(strict_types=1);

namespace AIGateway\Core;

/**
 * Token usage statistics from an LLM response.
 */
final readonly class Usage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
    ) {
    }

    /**
     * @param array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int} $data
     */
    public static function fromArray(array $data): self
    {
        $prompt = $data['prompt_tokens'] ?? 0;
        $completion = $data['completion_tokens'] ?? 0;
        $total = $data['total_tokens'] ?? ($prompt + $completion);

        return new self($prompt, $completion, $total);
    }

    /** @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int} */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }

    public function isEmpty(): bool
    {
        return 0 === $this->totalTokens;
    }
}
