<?php

declare(strict_types=1);

namespace AIGateway\Provider;

/**
 * Parsed error from a provider response.
 */
final readonly class ProviderError
{
    public function __construct(
        public string $code,
        public string $message,
        public string $type = 'provider_error',
        public bool $retryable = false,
    ) {
    }

    public static function nonRetryable(string $code, string $message, string $type = 'provider_error'): self
    {
        return new self($code, $message, $type, false);
    }

    public static function retryable(string $code, string $message, string $type = 'provider_error'): self
    {
        return new self($code, $message, $type, true);
    }
}
