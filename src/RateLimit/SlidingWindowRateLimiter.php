<?php

declare(strict_types=1);

namespace AIGateway\RateLimit;

use function count;
use function time;

final class SlidingWindowRateLimiter implements RateLimiterInterface
{
    /** @var array<string, list<int>> */
    private array $windows = [];

    public function __construct(
        private readonly int $maxRequests,
        private readonly int $windowSeconds,
    ) {
    }

    public function isAllowed(string $key): RateLimitResult
    {
        $now = time();
        $windowStart = $now - $this->windowSeconds;

        if (!isset($this->windows[$key])) {
            $this->windows[$key] = [];
        }

        $this->windows[$key] = array_values(array_filter(
            $this->windows[$key],
            static fn (int $timestamp): bool => $timestamp > $windowStart,
        ));

        $count = count($this->windows[$key]);
        $allowed = $count < $this->maxRequests;

        return new RateLimitResult(
            allowed: $allowed,
            limit: $this->maxRequests,
            remaining: max(0, $this->maxRequests - $count),
            resetAt: $now + $this->windowSeconds,
        );
    }

    public function increment(string $key): void
    {
        if (!isset($this->windows[$key])) {
            $this->windows[$key] = [];
        }

        $this->windows[$key][] = time();
    }

    public function reset(string $key): void
    {
        unset($this->windows[$key]);
    }
}
