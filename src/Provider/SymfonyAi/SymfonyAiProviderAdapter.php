<?php

declare(strict_types=1);

namespace AIGateway\Provider\SymfonyAi;

use AIGateway\Core\Choice;
use AIGateway\Core\Message;
use AIGateway\Core\NormalizedRequest;
use AIGateway\Core\NormalizedResponse;
use AIGateway\Core\NormalizedStreamChunk;
use AIGateway\Core\Usage;
use AIGateway\Provider\ProviderCapabilities;
use AIGateway\Provider\ProviderError;
use AIGateway\Provider\ProviderRequest;
use AIGateway\Provider\ProviderResponse;
use AIGateway\Provider\RuntimeProviderAdapterInterface;
use Generator;

use function in_array;
use function is_array;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use JsonException;
use LogicException;

use function sprintf;

use Symfony\AI\Platform\Exception\ExceptionInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

final readonly class SymfonyAiProviderAdapter implements RuntimeProviderAdapterInterface
{
    public function __construct(
        private string $name,
        private PlatformInterface $platform,
        private ProviderCapabilities $capabilities = new ProviderCapabilities(streaming: true, functionCalling: true),
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function chat(NormalizedRequest $request, string $requestedModel): NormalizedResponse
    {
        if ('' === $request->model) {
            throw new LogicException('Model cannot be empty.');
        }

        $deferred = $this->platform->invoke($request->model, $this->toMessageBag($request), $this->toOptions($request));

        try {
            $text = $deferred->asText();
        } catch (ExceptionInterface) {
            $text = '';
        }

        $toolCalls = [];

        try {
            foreach ($deferred->asToolCalls() as $toolCall) {
                $toolCalls[] = [
                    'id' => $toolCall->getId(),
                    'type' => 'function',
                    'function' => [
                        'name' => $toolCall->getName(),
                        'arguments' => json_encode($toolCall->getArguments(), JSON_THROW_ON_ERROR),
                    ],
                ];
            }
        } catch (ExceptionInterface|JsonException) {
        }

        $message = new Message(
            role: 'assistant',
            content: $text,
            toolCalls: [] !== $toolCalls ? $toolCalls : null,
        );

        $usage = $this->extractUsage($deferred->getMetadata()->get('token_usage'));

        return new NormalizedResponse(
            id: sprintf('%s-%s', $this->name, bin2hex(random_bytes(8))),
            model: $request->model,
            provider: $this->name,
            choices: [new Choice(0, $message, 'stop')],
            usage: $usage,
            statusCode: 200,
        );
    }

    public function chatStream(NormalizedRequest $request, string $requestedModel): Generator
    {
        if ('' === $request->model) {
            throw new LogicException('Model cannot be empty.');
        }

        $deferred = $this->platform->invoke($request->model, $this->toMessageBag($request), $this->toOptions($request, true));

        $i = 0;
        foreach ($deferred->asTextStream() as $delta) {
            ++$i;
            yield $i => new NormalizedStreamChunk(
                id: sprintf('%s-%s', $this->name, bin2hex(random_bytes(8))),
                model: $request->model,
                provider: $this->name,
                delta: $delta->getText(),
            );
        }

        yield ++$i => new NormalizedStreamChunk(
            id: sprintf('%s-%s', $this->name, bin2hex(random_bytes(8))),
            model: $request->model,
            provider: $this->name,
            delta: '',
            finishReason: 'stop',
            usage: $this->extractUsage($deferred->getMetadata()->get('token_usage')),
        );
    }

    public function translateRequest(NormalizedRequest $request): ProviderRequest
    {
        return new ProviderRequest('symfony-ai://runtime');
    }

    public function translateResponse(ProviderResponse $response, string $requestedModel): NormalizedResponse
    {
        throw new LogicException('SymfonyAiProviderAdapter uses runtime invocation and does not support HTTP translation.');
    }

    public function isRetryableError(int $statusCode, string $body): bool
    {
        return in_array($statusCode, [408, 429, 500, 502, 503, 504], true);
    }

    public function parseError(int $statusCode, string $body): ProviderError
    {
        return new ProviderError(
            code: (string) $statusCode,
            message: sprintf('%s provider error (%d): %s', $this->name, $statusCode, $body),
            type: $this->name.'_error',
            retryable: $this->isRetryableError($statusCode, $body),
        );
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return $this->capabilities;
    }

    private function toMessageBag(NormalizedRequest $request): MessageBag
    {
        $bag = new MessageBag();

        foreach ($request->messages as $message) {
            if ('system' === $message->role) {
                $bag->add(new SystemMessage($message->content));

                continue;
            }

            if ('assistant' === $message->role) {
                $toolCalls = null;
                if (null !== $message->toolCalls) {
                    $toolCalls = array_map(static function (array $toolCall): ToolCall {
                        $args = $toolCall['function']['arguments'] ?? '{}';
                        if (is_string($args)) {
                            try {
                                $args = json_decode($args, true, 512, JSON_THROW_ON_ERROR);
                            } catch (JsonException) {
                                $args = [];
                            }
                        }

                        return new ToolCall(
                            id: (string) ($toolCall['id'] ?? ''),
                            name: (string) ($toolCall['function']['name'] ?? ''),
                            arguments: is_array($args) ? $args : [],
                        );
                    }, $message->toolCalls);
                }

                $bag->add(new AssistantMessage($message->content, $toolCalls));

                continue;
            }

            if ('tool' === $message->role) {
                $id = $message->toolCallId ?? sprintf('tool_%s', bin2hex(random_bytes(4)));
                $toolCall = new ToolCall($id, $message->name ?? 'tool', []);
                $bag->add(new ToolCallMessage($toolCall, $message->content));

                continue;
            }

            $bag->add(new UserMessage(new Text($message->content)));
        }

        return $bag;
    }

    /**
     * @return array<string, mixed>
     */
    private function toOptions(NormalizedRequest $request, bool $forceStream = false): array
    {
        $options = [];

        if (null !== $request->temperature) {
            $options['temperature'] = $request->temperature;
        }

        if (null !== $request->topP) {
            $options['top_p'] = $request->topP;
        }

        if (null !== $request->maxTokens) {
            $options['max_tokens'] = $request->maxTokens;
        }

        if (null !== $request->stop) {
            $options['stop'] = $request->stop;
        }

        if ($request->stream || $forceStream) {
            $options['stream'] = true;
        }

        if (null !== $request->tools) {
            $options['tools'] = $request->tools;
        }

        return $options;
    }

    private function extractUsage(mixed $tokenUsage): Usage
    {
        if (!$tokenUsage instanceof TokenUsageInterface) {
            return new Usage(0, 0, 0);
        }

        $prompt = $tokenUsage->getPromptTokens() ?? 0;
        $completion = $tokenUsage->getCompletionTokens() ?? 0;
        $total = $tokenUsage->getTotalTokens();

        if (null === $total) {
            $total = $prompt + $completion;
        }

        return new Usage($prompt, $completion, $total);
    }
}
