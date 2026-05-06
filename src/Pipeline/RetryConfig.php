<?php

declare(strict_types=1);

namespace PhiGateway\Pipeline;

/**
 * Configuration for a retry + fallback pipeline.
 */
final readonly class RetryConfig
{
    public function __construct(
        public int $maxAttempts = 2,
        public int $delayMs = 1000,
        public string $backoff = 'exponential',
    ) {
    }

    public function getDelayForAttempt(int $attempt): int
    {
        if ('exponential' === $this->backoff) {
            return $this->delayMs * (2 ** $attempt);
        }

        return $this->delayMs;
    }
}
