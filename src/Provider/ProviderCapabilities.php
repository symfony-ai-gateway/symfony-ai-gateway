<?php

declare(strict_types=1);

namespace PhiGateway\Provider;

/**
 * Declared capabilities of a provider adapter.
 */
final readonly class ProviderCapabilities
{
    public function __construct(
        public bool $streaming = true,
        public bool $vision = false,
        public bool $audio = false,
        public bool $embeddings = false,
        public bool $functionCalling = true,
        public int $maxTokensPerRequest = 128000,
    ) {
    }
}
