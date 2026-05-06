<?php

declare(strict_types=1);

namespace PhiGateway\Core;

/**
 * Immutable value object representing a single message in a chat conversation.
 */
final readonly class Message
{
    /**
     * @param string $role One of: system, user, assistant, tool
     * @param string $content The text content of the message
     * @param string|null $name Optional sender name (for tool messages)
     * @param list<array<string, mixed>>|null $toolCalls Tool calls made by the assistant
     * @param string|null $toolCallId The tool call ID this message responds to
     */
    public function __construct(
        public string $role,
        public string $content,
        public ?string $name = null,
        public ?array $toolCalls = null,
        public ?string $toolCallId = null,
    ) {
    }

    /**
     * Create from a raw array (OpenAI format).
     *
     * @param array{role: string, content?: string|null, name?: string, tool_calls?: list<array<string, mixed>>, tool_call_id?: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: $data['role'],
            content: $data['content'] ?? '',
            name: $data['name'] ?? null,
            toolCalls: $data['tool_calls'] ?? null,
            toolCallId: $data['tool_call_id'] ?? null,
        );
    }

    /**
     * Convert to OpenAI-compatible array.
     *
     * @return array{role: string, content: string|null, name?: string, tool_calls?: list<array<string, mixed>>, tool_call_id?: string}
     */
    public function toArray(): array
    {
        $result = ['role' => $this->role];

        if ($this->content !== '') {
            $result['content'] = $this->content;
        } else {
            $result['content'] = null;
        }

        if ($this->name !== null) {
            $result['name'] = $this->name;
        }

        if ($this->toolCalls !== null) {
            $result['tool_calls'] = $this->toolCalls;
        }

        if ($this->toolCallId !== null) {
            $result['tool_call_id'] = $this->toolCallId;
        }

        return $result;
    }
}
