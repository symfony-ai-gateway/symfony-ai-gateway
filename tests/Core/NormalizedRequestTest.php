<?php

declare(strict_types=1);

namespace PhiGateway\Tests\Core;

use PHPUnit\Framework\TestCase;
use PhiGateway\Core\NormalizedRequest;
use PhiGateway\Exception\GatewayException;

final class NormalizedRequestTest extends TestCase
{
    public function testFromArrayWithMinimalPayload(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $this->assertSame('gpt-4o', $request->model);
        $this->assertCount(1, $request->messages);
        $this->assertSame('Hello', $request->messages[0]->content);
        $this->assertSame(1.0, $request->temperature);
        $this->assertFalse($request->stream);
    }

    public function testFromArrayWithFullPayload(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => 'You are helpful.'],
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'temperature' => 0.7,
            'max_tokens' => 2048,
            'top_p' => 0.9,
            'frequency_penalty' => 0.5,
            'presence_penalty' => 0.3,
            'stop' => ['\n'],
            'stream' => true,
            'seed' => 42,
            'user' => 'user-123',
        ]);

        $this->assertSame(0.7, $request->temperature);
        $this->assertSame(2048, $request->maxTokens);
        $this->assertSame(0.9, $request->topP);
        $this->assertSame(0.5, $request->frequencyPenalty);
        $this->assertSame(0.3, $request->presencePenalty);
        $this->assertSame(['\n'], $request->stop);
        $this->assertTrue($request->stream);
        $this->assertSame(42, $request->seed);
        $this->assertSame('user-123', $request->user);
    }

    public function testFromArrayFailsWithoutModel(): void
    {
        $this->expectException(GatewayException::class);

        NormalizedRequest::fromArray([
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);
    }

    public function testFromArrayFailsWithEmptyModel(): void
    {
        $this->expectException(GatewayException::class);

        NormalizedRequest::fromArray([
            'model' => '',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);
    }

    public function testFromArrayFailsWithoutMessages(): void
    {
        $this->expectException(GatewayException::class);

        NormalizedRequest::fromArray(['model' => 'gpt-4o']);
    }

    public function testFromArrayFailsWithEmptyMessages(): void
    {
        $this->expectException(GatewayException::class);

        NormalizedRequest::fromArray([
            'model' => 'gpt-4o',
            'messages' => [],
        ]);
    }

    public function testToHashIsDeterministic(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $this->assertSame($request->toHash(), $request->toHash());
    }

    public function testToHashDiffersForDifferentContent(): void
    {
        $a = NormalizedRequest::fromArray([
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $b = NormalizedRequest::fromArray([
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => 'World']],
        ]);

        $this->assertNotSame($a->toHash(), $b->toHash());
    }

    public function testToArrayProducesOpenAiFormat(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'temperature' => 0.5,
        ]);

        $array = $request->toArray();

        $this->assertSame('gpt-4o', $array['model']);
        $this->assertSame(0.5, $array['temperature']);
        $this->assertArrayNotHasKey('stream', $array);
        $this->assertArrayNotHasKey('max_tokens', $array);
    }
}
