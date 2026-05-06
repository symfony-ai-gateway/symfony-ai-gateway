<?php

declare(strict_types=1);

namespace AIGateway\Provider\Anthropic;

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
use AIGateway\Provider\StreamingProviderAdapterInterface;

use function in_array;

use const JSON_THROW_ON_ERROR;

use JsonException;

use function sprintf;

use stdClass;

final class AnthropicAdapter implements StreamingProviderAdapterInterface
{
    private const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MAX_TOKENS = 4096;

    public function __construct(
        private string $apiKey,
        private string $baseUrl = self::DEFAULT_BASE_URL,
        private int $timeoutSeconds = 30,
    ) {
    }

    public function getName(): string
    {
        return 'anthropic';
    }

    public function translateRequest(NormalizedRequest $request): ProviderRequest
    {
        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
        ];

        $body = $this->buildRequestBody($request);

        return new ProviderRequest(
            url: sprintf('%s/messages', rtrim($this->baseUrl, '/')),
            method: 'POST',
            headers: $headers,
            body: json_encode($body, JSON_THROW_ON_ERROR),
            timeoutSeconds: $this->timeoutSeconds,
        );
    }

    public function translateResponse(ProviderResponse $response, string $requestedModel): NormalizedResponse
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        $content = $data['content'] ?? [];
        $textParts = array_filter($content, static fn (array $block): bool => ($block['type'] ?? '') === 'text');
        $text = implode("\n", array_map(static fn (array $block): string => $block['text'] ?? '', $textParts));

        $toolUseBlocks = array_filter($content, static fn (array $block): bool => ($block['type'] ?? '') === 'tool_use');
        $toolCalls = $this->convertToolUseBlocks($toolUseBlocks);

        $message = new Message(
            role: 'assistant',
            content: $text,
            toolCalls: [] !== $toolCalls ? $toolCalls : null,
        );

        $finishReason = $this->mapFinishReason($data['stop_reason'] ?? null);

        $choice = new Choice(index: 0, message: $message, finishReason: $finishReason);

        $usage = new Usage(
            promptTokens: $data['usage']['input_tokens'] ?? 0,
            completionTokens: $data['usage']['output_tokens'] ?? 0,
            totalTokens: ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0),
        );

        return new NormalizedResponse(
            id: $data['id'] ?? sprintf('msg_%s', bin2hex(random_bytes(12))),
            model: $data['model'] ?? $requestedModel,
            provider: $this->getName(),
            choices: [$choice],
            usage: $usage,
            statusCode: $response->statusCode,
            systemFingerprint: null,
        );
    }

    public function isRetryableError(int $statusCode, string $body): bool
    {
        return in_array($statusCode, [429, 500, 502, 503, 529], true);
    }

    public function parseError(int $statusCode, string $body): ProviderError
    {
        $retryable = $this->isRetryableError($statusCode, $body);

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $error = $data['error'] ?? $data;

            return new ProviderError(
                code: (string) ($error['type'] ?? (string) $statusCode),
                message: (string) ($error['message'] ?? 'Unknown Anthropic error'),
                type: 'anthropic_error',
                retryable: $retryable,
            );
        } catch (JsonException) {
            return new ProviderError(
                code: (string) $statusCode,
                message: sprintf('Anthropic returned HTTP %d with non-JSON body', $statusCode),
                type: 'http_error',
                retryable: $retryable,
            );
        }
    }

    public function translateStreamChunk(string $rawChunk, string $requestedModel): NormalizedStreamChunk|null
    {
        $line = trim($rawChunk);

        $event = null;
        $json = null;

        if (str_starts_with($line, 'event: ')) {
            $event = trim(substr($line, 7));

            return null;
        }

        if (str_starts_with($line, 'data: ')) {
            $json = trim(substr($line, 6));
        }

        if (null === $json || '' === $json) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $type = $data['type'] ?? '';

        if ('content_block_delta' === $type) {
            $delta = $data['delta']['text'] ?? '';

            return new NormalizedStreamChunk(
                id: $data['message']['id'] ?? sprintf('msg_%s', bin2hex(random_bytes(8))),
                model: $data['model'] ?? $requestedModel,
                provider: $this->getName(),
                delta: $delta,
            );
        }

        if ('message_delta' === $type) {
            $stopReason = $data['delta']['stop_reason'] ?? null;
            $finishReason = $this->mapFinishReason($stopReason);
            $usage = null;

            if (isset($data['usage'])) {
                $usage = new Usage(
                    promptTokens: 0,
                    completionTokens: $data['usage']['output_tokens'] ?? 0,
                    totalTokens: $data['usage']['output_tokens'] ?? 0,
                );
            }

            return new NormalizedStreamChunk(
                id: sprintf('msg_%s', bin2hex(random_bytes(8))),
                model: $requestedModel,
                provider: $this->getName(),
                delta: '',
                finishReason: $finishReason,
                usage: $usage,
            );
        }

        return null;
    }

    public function isStreamDone(string $rawChunk): bool
    {
        $line = trim($rawChunk);

        if (str_starts_with($line, 'event: ')) {
            return 'message_stop' === trim(substr($line, 7));
        }

        return false;
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            streaming: true,
            vision: true,
            audio: false,
            embeddings: false,
            functionCalling: true,
            maxTokensPerRequest: 200000,
        );
    }

    /**
     * Build Anthropic-specific request body from normalized request.
     *
     * Key differences from OpenAI:
     * - "system" is a separate top-level parameter, not a message
     * - "max_tokens" is required
     * - "content" is always an array of content blocks
     * - "tools" use "name" directly instead of "function.name"
     *
     * @return array<string, mixed>
     */
    private function buildRequestBody(NormalizedRequest $request): array
    {
        $systemMessage = null;
        $messages = [];

        foreach ($request->messages as $message) {
            if ('system' === $message->role) {
                $systemMessage = $message->content;

                continue;
            }

            $messages[] = $this->convertMessage($message);
        }

        $body = [
            'model' => $request->model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens ?? self::DEFAULT_MAX_TOKENS,
        ];

        if (null !== $systemMessage) {
            $body['system'] = $systemMessage;
        }

        if (1.0 !== $request->temperature) {
            $body['temperature'] = $request->temperature;
        }

        if (null !== $request->topP) {
            $body['top_p'] = $request->topP;
        }

        if (null !== $request->stop) {
            $body['stop_sequences'] = $request->stop;
        }

        if ($request->stream) {
            $body['stream'] = true;
        }

        if (null !== $request->tools) {
            $body['tools'] = $this->convertTools($request->tools);
        }

        if (null !== $request->seed) {
            $body['metadata'] = ['user_id' => (string) $request->seed];
        }

        return $body;
    }

    /** @return array{role: string, content: string|array<int, array<string, mixed>>} */
    private function convertMessage(Message $message): array
    {
        if ('assistant' === $message->role && null !== $message->toolCalls) {
            $content = [];

            if ('' !== $message->content) {
                $content[] = ['type' => 'text', 'text' => $message->content];
            }

            foreach ($message->toolCalls as $toolCall) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $toolCall['id'] ?? '',
                    'name' => $toolCall['function']['name'] ?? '',
                    'input' => json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
                ];
            }

            return ['role' => 'assistant', 'content' => $content];
        }

        if ('tool' === $message->role) {
            return [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => $message->toolCallId ?? '',
                        'content' => $message->content,
                    ],
                ],
            ];
        }

        return ['role' => $message->role, 'content' => $message->content];
    }

    /**
     * @param list<array<string, mixed>> $openaiTools
     *
     * @return list<array{name: string, description?: string, input_schema: array<string, mixed>}>
     */
    private function convertTools(array $openaiTools): array
    {
        $result = [];
        foreach ($openaiTools as $tool) {
            $function = $tool['function'] ?? $tool;
            $converted = [
                'name' => $function['name'] ?? '',
                'input_schema' => $function['parameters'] ?? ['type' => 'object'],
            ];

            if (isset($function['description'])) {
                $converted['description'] = $function['description'];
            }

            $result[] = $converted;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $toolUseBlocks
     *
     * @return list<array<string, mixed>>
     */
    private function convertToolUseBlocks(array $toolUseBlocks): array
    {
        $result = [];
        foreach ($toolUseBlocks as $block) {
            $result[] = [
                'id' => $block['id'] ?? '',
                'type' => 'function',
                'function' => [
                    'name' => $block['name'] ?? '',
                    'arguments' => json_encode($block['input'] ?? new stdClass(), JSON_THROW_ON_ERROR),
                ],
            ];
        }

        return $result;
    }

    private function mapFinishReason(string|null $stopReason): string|null
    {
        return match ($stopReason) {
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'stop_sequence' => 'stop',
            'tool_use' => 'tool_calls',
            null => null,
            default => $stopReason,
        };
    }
}
