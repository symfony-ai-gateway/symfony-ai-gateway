<?php

declare(strict_types=1);

namespace PhiGateway\Tests\Core;

use PHPUnit\Framework\TestCase;
use PhiGateway\Core\Message;

final class MessageTest extends TestCase
{
    public function testFromArrayWithBasicMessage(): void
    {
        $message = Message::fromArray(['role' => 'user', 'content' => 'Hello']);

        $this->assertSame('user', $message->role);
        $this->assertSame('Hello', $message->content);
        $this->assertNull($message->name);
        $this->assertNull($message->toolCalls);
        $this->assertNull($message->toolCallId);
    }

    public function testFromArrayWithSystemMessage(): void
    {
        $message = Message::fromArray(['role' => 'system', 'content' => 'You are helpful.']);

        $this->assertSame('system', $message->role);
        $this->assertSame('You are helpful.', $message->content);
    }

    public function testFromArrayWithToolCalls(): void
    {
        $toolCalls = [
            ['id' => 'call_123', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Paris"}']],
        ];

        $message = Message::fromArray([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => $toolCalls,
        ]);

        $this->assertSame('assistant', $message->role);
        $this->assertSame($toolCalls, $message->toolCalls);
    }

    public function testToArrayRoundTrip(): void
    {
        $original = ['role' => 'user', 'content' => 'Hello'];

        $message = Message::fromArray($original);
        $array = $message->toArray();

        $this->assertSame('user', $array['role']);
        $this->assertSame('Hello', $array['content']);
    }

    public function testToArrayIncludesNullContent(): void
    {
        $message = new Message(role: 'assistant', content: '');

        $array = $message->toArray();
        $this->assertNull($array['content']);
    }
}
