<?php

declare(strict_types=1);

namespace PhiGateway\Tests\Provider;

use PHPUnit\Framework\TestCase;
use PhiGateway\Core\NormalizedRequest;
use PhiGateway\Provider\OpenAI\OpenAIAdapter;

final class OpenAIAdapterTest extends TestCase
{
    private OpenAIAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new OpenAIAdapter(apiKey: 'sk-test-fake-key');
    }

    public function testGetName(): void
    {
        $this->assertSame('openai', $this->adapter->getName());
    }

    public function testTranslateRequest(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => 'You are helpful.'],
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'temperature' => 0.7,
        ]);

        $providerRequest = $this->adapter->translateRequest($request);

        $this->assertSame('POST', $providerRequest->method);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $providerRequest->url);
        $this->assertSame('Bearer sk-test-fake-key', $providerRequest->headers['Authorization']);
        $this->assertSame('application/json', $providerRequest->headers['Content-Type']);

        $body = json_decode($providerRequest->body, true);
        $this->assertSame('gpt-4o', $body['model']);
        $this->assertSame(0.7, $body['temperature']);
        $this->assertCount(2, $body['messages']);
    }

    public function testTranslateRequestWithCustomBaseUrl(): void
    {
        $adapter = new OpenAIAdapter(apiKey: 'sk-test', baseUrl: 'https://custom.api.com/v1');
        $request = NormalizedRequest::fromArray([
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => 'test']],
        ]);

        $providerRequest = $adapter->translateRequest($request);

        $this->assertSame('https://custom.api.com/v1/chat/completions', $providerRequest->url);
    }

    public function testTranslateRequestWithOrganization(): void
    {
        $adapter = new OpenAIAdapter(apiKey: 'sk-test', organization: 'org-123');
        $request = NormalizedRequest::fromArray([
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => 'test']],
        ]);

        $providerRequest = $adapter->translateRequest($request);

        $this->assertSame('org-123', $providerRequest->headers['OpenAI-Organization']);
    }

    public function testTranslateResponse(): void
    {
        $openaiResponse = [
            'id' => 'chatcmpl-abc123',
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Hello! How can I help?'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 8,
                'total_tokens' => 18,
            ],
        ];

        $providerResponse = new \PhiGateway\Provider\ProviderResponse(
            statusCode: 200,
            body: json_encode($openaiResponse) ?: '',
        );

        $response = $this->adapter->translateResponse($providerResponse, 'gpt-4o');

        $this->assertSame('chatcmpl-abc123', $response->id);
        $this->assertSame('gpt-4o', $response->model);
        $this->assertSame('openai', $response->provider);
        $this->assertSame('Hello! How can I help?', $response->getContent());
        $this->assertSame('stop', $response->choices[0]->finishReason);
        $this->assertSame(10, $response->usage->promptTokens);
        $this->assertSame(8, $response->usage->completionTokens);
        $this->assertSame(18, $response->usage->totalTokens);
    }

    public function testIsRetryableError(): void
    {
        $this->assertTrue($this->adapter->isRetryableError(429, ''));
        $this->assertTrue($this->adapter->isRetryableError(500, ''));
        $this->assertTrue($this->adapter->isRetryableError(502, ''));
        $this->assertTrue($this->adapter->isRetryableError(503, ''));
        $this->assertFalse($this->adapter->isRetryableError(400, ''));
        $this->assertFalse($this->adapter->isRetryableError(401, ''));
        $this->assertFalse($this->adapter->isRetryableError(403, ''));
    }

    public function testParseError(): void
    {
        $errorBody = json_encode([
            'error' => [
                'message' => 'Rate limit exceeded',
                'type' => 'rate_limit_error',
                'code' => 'rate_limit_exceeded',
            ],
        ]);

        $error = $this->adapter->parseError(429, $errorBody ?: '');

        $this->assertTrue($error->retryable);
        $this->assertSame('Rate limit exceeded', $error->message);
    }

    public function testGetCapabilities(): void
    {
        $capabilities = $this->adapter->getCapabilities();

        $this->assertTrue($capabilities->streaming);
        $this->assertTrue($capabilities->vision);
        $this->assertTrue($capabilities->functionCalling);
    }
}
