<?php

declare(strict_types=1);

namespace PhiGateway\Core;

/**
 * A single choice in an LLM response.
 */
final readonly class Choice
{
    /**
     * @param int $index Position of this choice
     * @param Message $message The assistant's response message
     * @param string|null $finishReason Why generation stopped: "stop", "length", "tool_calls", "content_filter"
     */
    public function __construct(
        public int $index,
        public Message $message,
        public ?string $finishReason = null,
    ) {
    }

    /**
     * @param array{index?: int, message?: array<string, mixed>, finish_reason?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        $messageData = $data['message'] ?? [];
        if (!is_array($messageData)) {
            $messageData = [];
        }
        /** @var array{role?: string, content?: string|null, tool_calls?: list<array<string, mixed>>, tool_call_id?: string} $messageData */
        $message = Message::fromArray(array_merge(['role' => 'assistant', 'content' => ''], $messageData));

        return new self(
            index: $data['index'] ?? 0,
            message: $message,
            finishReason: $data['finish_reason'] ?? null,
        );
    }

    /** @return array{index: int, message: array<string, mixed>, finish_reason: string|null} */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'message' => $this->message->toArray(),
            'finish_reason' => $this->finishReason,
        ];
    }
}
