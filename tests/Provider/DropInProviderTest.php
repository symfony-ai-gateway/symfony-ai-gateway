<?php

declare(strict_types=1);

namespace AIGateway\Tests\Provider;

use AIGateway\Core\NormalizedRequest;
use AIGateway\Provider\DeepSeek\DeepSeekAdapter;
use AIGateway\Provider\Groq\GroqAdapter;
use AIGateway\Provider\Mistral\MistralAdapter;
use AIGateway\Provider\OpenRouter\OpenRouterAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DropInProviderTest extends TestCase
{
    /**
     * @param class-string<\AIGateway\Provider\OpenAICompatibleAdapter> $adapterClass
     * @param non-empty-string                                          $apiKey
     * @param non-empty-string                                          $expectedBaseUrl
     */
    #[DataProvider('dropInProviderData')]
    public function testTranslateRequestUrl(
        string $adapterClass,
        string $apiKey,
        string $expectedBaseUrl,
    ): void {
        $adapter = new $adapterClass($apiKey);
        $request = NormalizedRequest::fromArray([
            'model' => 'test-model',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $providerRequest = $adapter->translateRequest($request);

        self::assertNotEmpty($expectedBaseUrl);
        self::assertStringStartsWith($expectedBaseUrl, $providerRequest->url);
        self::assertSame('Bearer '.$apiKey, $providerRequest->headers['Authorization']);
        self::assertSame('POST', $providerRequest->method);
    }

    /**
     * @param class-string<\AIGateway\Provider\OpenAICompatibleAdapter> $adapterClass
     * @param non-empty-string                                          $apiKey
     */
    #[DataProvider('dropInProviderData')]
    public function testTranslateResponse(
        string $adapterClass,
        string $apiKey,
        string $expectedBaseUrl,
    ): void {
        $adapter = new $adapterClass($apiKey);

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
    }

    /**
     * @return list<array{class-string<\AIGateway\Provider\OpenAICompatibleAdapter>, non-empty-string, non-empty-string}>
     */
    public static function dropInProviderData(): array
    {
        return [
            [MistralAdapter::class, 'mistral-key', 'https://api.mistral.ai/v1/chat/completions'],
            [DeepSeekAdapter::class, 'deepseek-key', 'https://api.deepseek.com/v1/chat/completions'],
            [GroqAdapter::class, 'gsk-test', 'https://api.groq.com/openai/v1/chat/completions'],
            [OpenRouterAdapter::class, 'sk-or-test', 'https://openrouter.ai/api/v1/chat/completions'],
        ];
    }
}
