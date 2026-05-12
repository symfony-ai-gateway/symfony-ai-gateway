<?php

declare(strict_types=1);

namespace AIGateway\RateLimit;

use AIGateway\Exception\GatewayException;

final class MultiLevelRateLimiter
{
    private bool $enabled;

    /**
     * @param array<string, RateLimiterInterface> $limiters key = scope name
     */
    public function __construct(
        private readonly array $limiters = [],
        bool $enabled = true,
    ) {
        $this->enabled = $enabled;
    }

    /**
     * @param array<string, string> $keys scope name → key value
     */
    public function check(array $keys): void
    {
        if (!$this->enabled) {
            return;
        }

        foreach ($this->limiters as $scope => $limiter) {
            $key = $keys[$scope] ?? $scope;
            $result = $limiter->isAllowed($key);

            if (!$result->allowed) {
                throw GatewayException::rateLimited($scope, $result->limit, $result->resetAt);
            }
        }
    }

    /**
     * @param array<string, string> $keys scope name → key value
     */
    public function increment(array $keys): void
    {
        if (!$this->enabled) {
            return;
        }

        foreach ($this->limiters as $scope => $limiter) {
            $key = $keys[$scope] ?? $scope;
            $limiter->increment($key);
        }
    }
}
