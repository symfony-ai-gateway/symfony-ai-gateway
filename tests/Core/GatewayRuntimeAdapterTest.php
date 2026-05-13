<?php

declare(strict_types=1);

namespace AIGateway\Tests\Core;

use AIGateway\Config\ModelRegistry;
use AIGateway\Core\Choice;
use AIGateway\Core\Gateway;
use AIGateway\Core\Message;
use AIGateway\Core\NormalizedRequest;
use AIGateway\Core\NormalizedResponse;
use AIGateway\Core\NormalizedStreamChunk;
use AIGateway\Core\ProviderHttpClient;
use AIGateway\Core\StreamProxy;
use AIGateway\Core\Usage;
use AIGateway\Provider\ProviderCapabilities;
use AIGateway\Provider\ProviderError;
use AIGateway\Provider\ProviderRequest;
use AIGateway\Provider\ProviderResponse;
use AIGateway\Provider\RuntimeProviderAdapterInterface;
use Generator;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GatewayRuntimeAdapterTest extends TestCase
{
    public function testChatUsesRuntimeProviderAdapter(): void
    {
        $gateway = $this->createGatewayWithRuntimeAdapter();

        $request = NormalizedRequest::fromArray([
            'model' => 'test-model',
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]);

        $response = $gateway->chat($request);

        self::assertSame('runtime', $response->provider);
        self::assertSame('runtime-content', $response->getContent());
    }

    public function testChatStreamUsesRuntimeProviderAdapter(): void
    {
        $gateway = $this->createGatewayWithRuntimeAdapter();

        $request = NormalizedRequest::fromArray([
            'model' => 'test-model',
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]);

        $chunks = iterator_to_array($gateway->chatStream($request));

        self::assertCount(2, $chunks);
        self::assertSame('part-1', $chunks[1]->delta);
        self::assertSame('stop', $chunks[2]->finishReason);
    }

    private function createGatewayWithRuntimeAdapter(): Gateway
    {
        $registry = new ModelRegistry([
            'test-model' => [
                'provider' => 'runtime',
                'model' => 'runtime-model',
                'pricing' => ['input' => 0.0, 'output' => 0.0],
            ],
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $providerHttpClient = new ProviderHttpClient($httpClient);
        $streamProxy = new StreamProxy($httpClient);

        $runtimeAdapter = new class implements RuntimeProviderAdapterInterface {
            public function getName(): string
            {
                return 'runtime';
            }

            public function chat(NormalizedRequest $request, string $requestedModel): NormalizedResponse
            {
                return new NormalizedResponse(
                    id: 'r1',
                    model: $requestedModel,
                    provider: 'runtime',
                    choices: [new Choice(0, new Message('assistant', 'runtime-content'), 'stop')],
                    usage: new Usage(1, 1, 2),
                );
            }

            public function chatStream(NormalizedRequest $request, string $requestedModel): Generator
            {
                yield 1 => new NormalizedStreamChunk('s1', $requestedModel, 'runtime', 'part-1');
                yield 2 => new NormalizedStreamChunk('s2', $requestedModel, 'runtime', '', 'stop', new Usage(1, 1, 2));
            }

            public function translateRequest(NormalizedRequest $request): ProviderRequest
            {
                return new ProviderRequest('runtime://noop');
            }

            public function translateResponse(ProviderResponse $response, string $requestedModel): NormalizedResponse
            {
                throw new LogicException('not used');
            }

            public function isRetryableError(int $statusCode, string $body): bool
            {
                return false;
            }

            public function parseError(int $statusCode, string $body): ProviderError
            {
                return new ProviderError((string) $statusCode, $body, 'runtime_error', false);
            }

            public function getCapabilities(): ProviderCapabilities
            {
                return new ProviderCapabilities(streaming: true, functionCalling: true);
            }
        };

        return new Gateway(
            modelRegistry: $registry,
            httpClient: $providerHttpClient,
            streamProxy: $streamProxy,
            providers: ['runtime' => $runtimeAdapter],
        );
    }
}
