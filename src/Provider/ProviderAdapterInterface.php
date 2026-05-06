<?php

declare(strict_types=1);

namespace AIGateway\Provider;

use AIGateway\Core\NormalizedRequest;
use AIGateway\Core\NormalizedResponse;

/**
 * Contract for every LLM provider adapter.
 *
 * Each provider (OpenAI, Anthropic, Ollama, etc.) implements this interface
 * to translate between the normalized gateway format and the provider-specific API.
 */
interface ProviderAdapterInterface
{
    /** Unique provider identifier (e.g. "openai", "anthropic", "ollama"). */
    public function getName(): string;

    /** Translate a normalized request into a provider-specific HTTP request. */
    public function translateRequest(NormalizedRequest $request): ProviderRequest;

    /** Translate a provider-specific HTTP response into normalized format. */
    public function translateResponse(ProviderResponse $response, string $requestedModel): NormalizedResponse;

    /** Whether the given HTTP error status + body is worth retrying. */
    public function isRetryableError(int $statusCode, string $body): bool;

    /** Parse a provider error body into a structured error. */
    public function parseError(int $statusCode, string $body): ProviderError;

    /** Declared capabilities of this provider. */
    public function getCapabilities(): ProviderCapabilities;
}
