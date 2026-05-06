<?php

declare(strict_types=1);

namespace AIGateway\Tests\Provider;

use AIGateway\Core\NormalizedRequest;
use AIGateway\Provider\Ollama\OllamaAdapter;
use PHPUnit\Framework\TestCase;

final class OllamaAdapterTest extends TestCase
{
    private OllamaAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new OllamaAdapter();
    }

    public function testGetName(): void
    {
        $this->assertSame('ollama', $this->adapter->getName());
    }

    public function testTranslateRequestFormat(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'llama3',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $providerRequest = $this->adapter->translateRequest($request);

        $this->assertSame('http://localhost:11434/api/chat', $providerRequest->url);
        $this->assertArrayNotHasKey('Authorization', $providerRequest->headers);
        $this->assertSame(120, $providerRequest->timeoutSeconds);

        $body = json_decode($providerRequest->body, true);
        $this->assertSame('llama3', $body['model']);
        $this->assertFalse($body['stream']);
    }

    public function testTranslateRequestWithCustomBaseUrl(): void
    {
        $adapter = new OllamaAdapter(baseUrl: 'http://192.168.1.100:11434');
        $request = NormalizedRequest::fromArray([
            'model' => 'llama3',
            'messages' => [['role' => 'user', 'content' => 'test']],
        ]);

        $providerRequest = $adapter->translateRequest($request);

        $this->assertSame('http://192.168.1.100:11434/api/chat', $providerRequest->url);
    }

    public function testTranslateResponse(): void
    {
        $ollamaResponse = [
            'model' => 'llama3',
            'message' => ['role' => 'assistant', 'content' => 'Hello from Ollama!'],
            'done' => true,
            'prompt_eval_count' => 12,
            'eval_count' => 8,
        ];

        $providerResponse = new \AIGateway\Provider\ProviderResponse(
            statusCode: 200,
            body: json_encode($ollamaResponse) ?: '',
        );

        $response = $this->adapter->translateResponse($providerResponse, 'llama3');

        $this->assertSame('ollama', $response->provider);
        $this->assertSame('Hello from Ollama!', $response->getContent());
        $this->assertSame('stop', $response->choices[0]->finishReason);
        $this->assertSame(12, $response->usage->promptTokens);
        $this->assertSame(8, $response->usage->completionTokens);
        $this->assertSame(20, $response->usage->totalTokens);
    }

    public function testTranslateResponseWithEstimatedTokens(): void
    {
        $ollamaResponse = [
            'model' => 'llama3',
            'message' => ['role' => 'assistant', 'content' => 'Short reply.'],
            'done' => true,
        ];

        $providerResponse = new \AIGateway\Provider\ProviderResponse(
            statusCode: 200,
            body: json_encode($ollamaResponse) ?: '',
        );

        $response = $this->adapter->translateResponse($providerResponse, 'llama3');

        $this->assertGreaterThan(0, $response->usage->completionTokens);
    }

    public function testIsRetryableError(): void
    {
        $this->assertTrue($this->adapter->isRetryableError(500, ''));
        $this->assertFalse($this->adapter->isRetryableError(400, ''));
    }

    public function testNoAuthHeaders(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'llama3',
            'messages' => [['role' => 'user', 'content' => 'test']],
        ]);

        $providerRequest = $this->adapter->translateRequest($request);

        $this->assertArrayNotHasKey('Authorization', $providerRequest->headers);
        $this->assertArrayNotHasKey('x-api-key', $providerRequest->headers);
    }
}
