<?php

declare(strict_types=1);

namespace AIGateway\Provider\Gemini;

use AIGateway\Core\Choice;
use AIGateway\Core\Message;
use AIGateway\Core\NormalizedRequest;
use AIGateway\Core\NormalizedResponse;
use AIGateway\Core\Usage;
use AIGateway\Provider\ProviderAdapterInterface;
use AIGateway\Provider\ProviderCapabilities;
use AIGateway\Provider\ProviderError;
use AIGateway\Provider\ProviderRequest;
use AIGateway\Provider\ProviderResponse;

use function in_array;

use const JSON_THROW_ON_ERROR;

use JsonException;

use function sprintf;

use stdClass;

final class GeminiAdapter implements ProviderAdapterInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function translateRequest(NormalizedRequest $request): ProviderRequest
    {
        $body = $this->buildRequestBody($request);

        return new ProviderRequest(
            url: sprintf(
                '%s/models/%s:generateContent?key=%s',
                rtrim($this->baseUrl, '/'),
                $request->model,
                $this->apiKey,
            ),
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body, JSON_THROW_ON_ERROR),
            timeoutSeconds: $this->timeoutSeconds,
        );
    }

    public function translateResponse(ProviderResponse $response, string $requestedModel): NormalizedResponse
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        $candidates = $data['candidates'] ?? [];
        $choices = [];

        foreach ($candidates as $index => $candidate) {
            $content = $candidate['content'] ?? [];
            $parts = $content['parts'] ?? [];
            $text = implode("\n", array_map(
                static fn (array $part): string => $part['text'] ?? '',
                array_filter($parts, static fn (array $part): bool => isset($part['text'])),
            ));

            $functionCalls = array_filter($parts, static fn (array $part): bool => isset($part['functionCall']));
            $toolCalls = $this->convertFunctionCalls(array_values($functionCalls));

            $message = new Message(
                role: 'assistant',
                content: $text,
                toolCalls: [] !== $toolCalls ? $toolCalls : null,
            );

            $choices[] = new Choice(
                index: $index,
                message: $message,
                finishReason: $this->mapFinishReason($candidate['finishReason'] ?? null),
            );
        }

        if ([] === $choices) {
            $choices[] = new Choice(index: 0, message: new Message(role: 'assistant', content: ''));
        }

        $usageMetadata = $data['usageMetadata'] ?? [];
        $usage = new Usage(
            promptTokens: $usageMetadata['promptTokenCount'] ?? 0,
            completionTokens: $usageMetadata['candidatesTokenCount'] ?? 0,
            totalTokens: $usageMetadata['totalTokenCount'] ?? 0,
        );

        return new NormalizedResponse(
            id: sprintf('gemini-%s', bin2hex(random_bytes(8))),
            model: $data['modelVersion'] ?? $requestedModel,
            provider: $this->getName(),
            choices: $choices,
            usage: $usage,
            statusCode: $response->statusCode,
        );
    }

    public function isRetryableError(int $statusCode, string $body): bool
    {
        return in_array($statusCode, [429, 500, 502, 503, 504], true);
    }

    public function parseError(int $statusCode, string $body): ProviderError
    {
        $retryable = $this->isRetryableError($statusCode, $body);

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $error = $data['error'] ?? $data;

            return new ProviderError(
                code: (string) ($error['code'] ?? $error['status'] ?? (string) $statusCode),
                message: (string) ($error['message'] ?? 'Unknown Gemini error'),
                type: (string) ($error['status'] ?? 'gemini_error'),
                retryable: $retryable,
            );
        } catch (JsonException) {
            return new ProviderError(
                code: (string) $statusCode,
                message: sprintf('Gemini returned HTTP %d with non-JSON body', $statusCode),
                type: 'http_error',
                retryable: $retryable,
            );
        }
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            streaming: true,
            vision: true,
            audio: false,
            embeddings: true,
            functionCalling: true,
            maxTokensPerRequest: 2000000,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestBody(NormalizedRequest $request): array
    {
        $systemInstruction = null;
        $contents = [];

        foreach ($request->messages as $message) {
            if ('system' === $message->role) {
                $systemInstruction = ['parts' => [['text' => $message->content]]];

                continue;
            }

            $contents[] = [
                'role' => 'assistant' === $message->role ? 'model' : $message->role,
                'parts' => $this->buildParts($message),
            ];
        }

        $body = ['contents' => $contents];

        if (null !== $systemInstruction) {
            $body['systemInstruction'] = $systemInstruction;
        }

        $generationConfig = [];

        if (null !== $request->temperature && 1.0 !== $request->temperature) {
            $generationConfig['temperature'] = $request->temperature;
        }

        if (null !== $request->topP) {
            $generationConfig['topP'] = $request->topP;
        }

        if (null !== $request->maxTokens) {
            $generationConfig['maxOutputTokens'] = $request->maxTokens;
        }

        if (null !== $request->stop) {
            $generationConfig['stopSequences'] = $request->stop;
        }

        if ([] !== $generationConfig) {
            $body['generationConfig'] = $generationConfig;
        }

        if (null !== $request->tools) {
            $body['tools'] = $this->convertTools($request->tools);
        }

        return $body;
    }

    /**
     * @return list<array{text: string}|array<string, mixed>>
     */
    private function buildParts(Message $message): array
    {
        if (null !== $message->toolCallId) {
            return [['functionResponse' => [
                'name' => $message->name ?? '',
                'response' => ['content' => $message->content],
            ]]];
        }

        return [['text' => $message->content]];
    }

    /**
     * @param list<array<string, mixed>> $openaiTools
     *
     * @return list<array{functionDeclarations: list<array<string, mixed>>}>
     */
    private function convertTools(array $openaiTools): array
    {
        $declarations = [];

        foreach ($openaiTools as $tool) {
            $function = $tool['function'] ?? $tool;
            $decl = [
                'name' => $function['name'] ?? '',
                'parameters' => $function['parameters'] ?? ['type' => 'object'],
            ];

            if (isset($function['description'])) {
                $decl['description'] = $function['description'];
            }

            $declarations[] = $decl;
        }

        return [['functionDeclarations' => $declarations]];
    }

    /**
     * @param list<array<string, mixed>> $functionCalls
     *
     * @return list<array<string, mixed>>
     */
    private function convertFunctionCalls(array $functionCalls): array
    {
        $result = [];

        foreach ($functionCalls as $fc) {
            $result[] = [
                'id' => sprintf('call_%s', bin2hex(random_bytes(4))),
                'type' => 'function',
                'function' => [
                    'name' => $fc['functionCall']['name'] ?? '',
                    'arguments' => json_encode($fc['functionCall']['args'] ?? new stdClass(), JSON_THROW_ON_ERROR),
                ],
            ];
        }

        return $result;
    }

    private function mapFinishReason(string|null $reason): string|null
    {
        return match ($reason) {
            'STOP' => 'stop',
            'MAX_TOKENS' => 'length',
            'SAFETY' => 'content_filter',
            'RECITATION' => 'content_filter',
            null => null,
            default => $reason,
        };
    }
}
