<?php

declare(strict_types=1);

namespace PhiGateway\Tests\Provider;

use PHPUnit\Framework\TestCase;
use PhiGateway\Core\NormalizedRequest;
use PhiGateway\Provider\Anthropic\AnthropicAdapter;

final class AnthropicAdapterTest extends TestCase
{
    private AnthropicAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new AnthropicAdapter(apiKey: 'sk-ant-test-fake-key');
    }

    public function testGetName(): void
    {
        $this->assertSame('anthropic', $this->adapter->getName());
    }

    public function testTranslateRequestExtractsSystemMessage(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'claude-sonnet-4-20250514',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => 'Hello'],
            ],
        ]);

        $providerRequest = $this->adapter->translateRequest($request);
        $body = json_decode($providerRequest->body, true);

        $this->assertSame('You are a helpful assistant.', $body['system']);
        $this->assertCount(1, $body['messages']);
        $this->assertSame('user', $body['messages'][0]['role']);
    }

    public function testTranslateRequestAddsMaxTokens(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'claude-sonnet-4-20250514',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $providerRequest = $this->adapter->translateRequest($request);
        $body = json_decode($providerRequest->body, true);

        $this->assertSame(4096, $body['max_tokens']);
    }

    public function testTranslateRequestHeaders(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'claude-sonnet-4-20250514',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $providerRequest = $this->adapter->translateRequest($request);

        $this->assertSame('sk-ant-test-fake-key', $providerRequest->headers['x-api-key']);
        $this->assertSame('2023-06-01', $providerRequest->headers['anthropic-version']);
    }

    public function testTranslateResponseWithTextContent(): void
    {
        $anthropicResponse = [
            'id' => 'msg_abc123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [
                ['type' => 'text', 'text' => 'Hello! How can I help?'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 15,
                'output_tokens' => 10,
            ],
        ];

        $providerResponse = new \PhiGateway\Provider\ProviderResponse(
            statusCode: 200,
            body: json_encode($anthropicResponse) ?: '',
        );

        $response = $this->adapter->translateResponse($providerResponse, 'claude-sonnet-4-20250514');

        $this->assertSame('msg_abc123', $response->id);
        $this->assertSame('anthropic', $response->provider);
        $this->assertSame('Hello! How can I help?', $response->getContent());
        $this->assertSame('stop', $response->choices[0]->finishReason);
        $this->assertSame(15, $response->usage->promptTokens);
        $this->assertSame(10, $response->usage->completionTokens);
        $this->assertSame(25, $response->usage->totalTokens);
    }

    public function testTranslateResponseWithToolUse(): void
    {
        $anthropicResponse = [
            'id' => 'msg_tool123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-20250514',
            'content' => [
                ['type' => 'text', 'text' => 'Let me check that.'],
                ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'get_weather', 'input' => ['city' => 'Paris']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 30],
        ];

        $providerResponse = new \PhiGateway\Provider\ProviderResponse(
            statusCode: 200,
            body: json_encode($anthropicResponse) ?: '',
        );

        $response = $this->adapter->translateResponse($providerResponse, 'claude-sonnet-4-20250514');

        $this->assertSame('tool_calls', $response->choices[0]->finishReason);
        $this->assertNotNull($response->choices[0]->message->toolCalls);
        $this->assertCount(1, $response->choices[0]->message->toolCalls);
        $this->assertSame('get_weather', $response->choices[0]->message->toolCalls[0]['function']['name']);
    }

    public function testMapFinishReason(): void
    {
        $this->assertFalse($this->adapter->isRetryableError(400, ''));
        $this->assertTrue($this->adapter->isRetryableError(429, ''));
        $this->assertTrue($this->adapter->isRetryableError(529, ''));
    }

    public function testTranslateRequestWithTools(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'claude-sonnet-4-20250514',
            'messages' => [['role' => 'user', 'content' => 'Weather in Paris?']],
            'tools' => [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'description' => 'Get weather for a city',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => ['city' => ['type' => 'string']],
                            'required' => ['city'],
                        ],
                    ],
                ],
            ],
        ]);

        $providerRequest = $this->adapter->translateRequest($request);
        $body = json_decode($providerRequest->body, true);

        $this->assertArrayHasKey('tools', $body);
        $this->assertSame('get_weather', $body['tools'][0]['name']);
        $this->assertArrayHasKey('input_schema', $body['tools'][0]);
    }
}
