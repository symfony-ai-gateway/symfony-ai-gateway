<?php

declare(strict_types=1);

namespace AIGateway\RateLimit;

interface RateLimiterInterface
{
    public function isAllowed(string $key): RateLimitResult;

    public function increment(string $key): void;

    public function reset(string $key): void;
}
