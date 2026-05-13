<?php

declare(strict_types=1);

namespace AIGateway\Provider;

use AIGateway\Core\NormalizedStreamChunk;

/**
 * Extended adapter contract for providers that support streaming responses.
 */
interface StreamingProviderAdapterInterface extends ProviderAdapterInterface
{
    /**
     * Translate a raw chunk from the provider's stream into a normalized chunk.
     * Returns null if the chunk should be skipped (e.g. ping event, empty delta).
     */
    public function translateStreamChunk(string $rawChunk, string $requestedModel): NormalizedStreamChunk|null;

    /**
     * Whether this raw chunk signals the end of the stream.
     */
    public function isStreamDone(string $rawChunk): bool;
}
