<?php

declare(strict_types=1);

namespace AIGateway\Tests\Provider;

use AIGateway\Core\NormalizedRequest;
use AIGateway\Provider\OpenAICompatibleAdapter;
use AIGateway\Provider\ProviderCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenAICompatibleAdapterTest extends TestCase
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $apiKey
     * @param non-empty-string $baseUrl
     */
    #[DataProvider('providerData')]
    public function testTranslateRequestUrl(
        string $name,
        string $apiKey,
        string $baseUrl,
    ): void {
        $adapter = new OpenAICompatibleAdapter($name, $apiKey, $baseUrl);
        $request = NormalizedRequest::fromArray([
            'model' => 'test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $providerRequest = $adapter->translateRequest($request);

        self::assertNotEmpty($baseUrl);
        self::assertStringStartsWith($baseUrl, $providerRequest->url);
        self::assertSame('Bearer '.$apiKey, $providerRequest->headers['Authorization']);
        self::assertSame('POST', $providerRequest->method);
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $apiKey
     */
    #[DataProvider('providerData')]
    public function testTranslateResponse(
        string $name,
        string $apiKey,
        string $baseUrl,
    ): void {
        $adapter = new OpenAICompatibleAdapter($name, $apiKey, $baseUrl);

        $openaiResponse = [
            'id' => 'chatcmpl-test',
            'model' => 'test-model',
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hi!'], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
        ];

        $providerResponse = new \AIGateway\Provider\ProviderResponse(
            statusCode: 200,
            body: json_encode($openaiResponse) ?: '',
        );

        $response = $adapter->translateResponse($providerResponse, 'test-model');

        self::assertSame('Hi!', $response->getContent());
        self::assertSame(8, $response->usage->totalTokens);
        self::assertSame($name, $response->provider);
    }

    /**
     * @param non-empty-string $name
     */
    #[DataProvider('providerData')]
    public function testCustomCapabilities(
        string $name,
        string $apiKey,
        string $baseUrl,
    ): void {
        $caps = new ProviderCapabilities(
            streaming: false,
            vision: true,
            functionCalling: false,
            maxTokensPerRequest: 64000,
        );

        $adapter = new OpenAICompatibleAdapter($name, $apiKey, $baseUrl, 30, $caps);

        self::assertFalse($adapter->getCapabilities()->streaming);
        self::assertTrue($adapter->getCapabilities()->vision);
        self::assertFalse($adapter->getCapabilities()->functionCalling);
        self::assertSame(64000, $adapter->getCapabilities()->maxTokensPerRequest);
    }

    /**
     * @return list<array{non-empty-string, non-empty-string, non-empty-string}>
     */
    public static function providerData(): array
    {
        return [
            ['mistral', 'mistral-key', 'https://api.mistral.ai/v1'],
            ['deepseek', 'deepseek-key', 'https://api.deepseek.com/v1'],
            ['groq', 'gsk-test', 'https://api.groq.com/openai/v1'],
            ['openrouter', 'sk-or-test', 'https://openrouter.ai/api/v1'],
            ['zai', 'zai-key', 'https://api.zai.com/v1'],
            ['my-custom-llm', 'custom-key', 'https://llm.example.com/v1'],
        ];
    }
}
