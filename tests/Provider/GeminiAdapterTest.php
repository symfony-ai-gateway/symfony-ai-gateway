<?php

declare(strict_types=1);

namespace AIGateway\Tests\Provider;

use AIGateway\Core\NormalizedRequest;
use AIGateway\Provider\Gemini\GeminiAdapter;
use PHPUnit\Framework\TestCase;

final class GeminiAdapterTest extends TestCase
{
    private GeminiAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new GeminiAdapter(apiKey: 'AIza-test-fake');
    }

    public function testGetName(): void
    {
        $this->assertSame('gemini', $this->adapter->getName());
    }

    public function testTranslateRequestUrlContainsKey(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'gemini-2.0-flash',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $providerRequest = $this->adapter->translateRequest($request);

        $this->assertStringContainsString('models/gemini-2.0-flash:generateContent', $providerRequest->url);
        $this->assertStringContainsString('key=AIza-test-fake', $providerRequest->url);
        $this->assertArrayNotHasKey('Authorization', $providerRequest->headers);
    }

    public function testTranslateRequestExtractsSystemInstruction(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'gemini-2.0-flash',
            'messages' => [
                ['role' => 'system', 'content' => 'Be helpful.'],
                ['role' => 'user', 'content' => 'Hello'],
            ],
        ]);

        $providerRequest = $this->adapter->translateRequest($request);
        $body = json_decode($providerRequest->body, true);

        $this->assertSame('Be helpful.', $body['systemInstruction']['parts'][0]['text']);
        $this->assertCount(1, $body['contents']);
        $this->assertSame('user', $body['contents'][0]['role']);
    }

    public function testTranslateRequestMapsRoles(): void
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'gemini-2.0-flash',
            'messages' => [
                ['role' => 'user', 'content' => 'Hi'],
                ['role' => 'assistant', 'content' => 'Hello!'],
                ['role' => 'user', 'content' => 'How are you?'],
            ],
        ]);

        $providerRequest = $this->adapter->translateRequest($request);
        $body = json_decode($providerRequest->body, true);

        $this->assertSame('user', $body['contents'][0]['role']);
        $this->assertSame('model', $body['contents'][1]['role']);
        $this->assertSame('user', $body['contents'][2]['role']);
    }

    public function testTranslateResponseWithText(): void
    {
        $geminiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => 'Hello from Gemini!']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 5,
                'candidatesTokenCount' => 10,
                'totalTokenCount' => 15,
            ],
            'modelVersion' => 'gemini-2.0-flash',
        ];

        $providerResponse = new \AIGateway\Provider\ProviderResponse(
            statusCode: 200,
            body: json_encode($geminiResponse) ?: '',
        );

        $response = $this->adapter->translateResponse($providerResponse, 'gemini-2.0-flash');

        $this->assertSame('gemini', $response->provider);
        $this->assertSame('Hello from Gemini!', $response->getContent());
        $this->assertSame('stop', $response->choices[0]->finishReason);
        $this->assertSame(5, $response->usage->promptTokens);
        $this->assertSame(10, $response->usage->completionTokens);
        $this->assertSame(15, $response->usage->totalTokens);
    }

    public function testTranslateResponseWithFunctionCall(): void
    {
        $geminiResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Paris']]],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
        ];

        $providerResponse = new \AIGateway\Provider\ProviderResponse(
            statusCode: 200,
            body: json_encode($geminiResponse) ?: '',
        );

        $response = $this->adapter->translateResponse($providerResponse, 'gemini-2.0-flash');

        $this->assertNotNull($response->choices[0]->message->toolCalls);
        $this->assertSame('get_weather', $response->choices[0]->message->toolCalls[0]['function']['name']);
    }

    public function testMapFinishReason(): void
    {
        $this->assertFalse($this->adapter->isRetryableError(400, ''));
        $this->assertTrue($this->adapter->isRetryableError(429, ''));
    }
}
