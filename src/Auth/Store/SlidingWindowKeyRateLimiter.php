<?php

declare(strict_types=1);

namespace AIGateway\Auth\Store;

use AIGateway\RateLimit\RateLimitResult;

use function count;
use function time;

final class SlidingWindowKeyRateLimiter
{
    /** @var array<string, list<int>> */
    private array $windows = [];

    public function isAllowed(string $keyId, int $maxPerMinute): RateLimitResult
    {
        $now = time();
        $windowStart = $now - 60;

        if (!isset($this->windows[$keyId])) {
            $this->windows[$keyId] = [];
        }

        $this->windows[$keyId] = array_values(array_filter(
            $this->windows[$keyId],
            static fn (int $timestamp): bool => $timestamp > $windowStart,
        ));

        $count = count($this->windows[$keyId]);
        $allowed = $count < $maxPerMinute;

        return new RateLimitResult(
            allowed: $allowed,
            limit: $maxPerMinute,
            remaining: max(0, $maxPerMinute - $count),
            resetAt: $now + 60,
        );
    }

    public function increment(string $keyId): void
    {
        if (!isset($this->windows[$keyId])) {
            $this->windows[$keyId] = [];
        }

        $this->windows[$keyId][] = time();
    }
}
