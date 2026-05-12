<?php

declare(strict_types=1);

namespace AIGateway\Auth;

use AIGateway\Auth\Store\KeyStoreInterface;
use AIGateway\Auth\Store\SlidingWindowKeyRateLimiter;
use AIGateway\Exception\GatewayException;

use function date;
use function sprintf;

final class AuthEnforcer
{
    public function __construct(
        private readonly KeyStoreInterface $keyStore,
        private readonly SlidingWindowKeyRateLimiter $rateLimiter,
    ) {
    }

    public function checkModelAllowed(ApiKeyContext $context, string $model): void
    {
        if (!$context->resolvedRules->isModelAllowed($model)) {
            throw GatewayException::forbidden(sprintf('Model "%s" is not allowed for API key "%s".', $model, $context->apiKey->name));
        }
    }

    public function checkBudget(ApiKeyContext $context): void
    {
        $today = date('Y-m-d');

        if (null !== $context->resolvedRules->budgetPerDay) {
            $dailyUsage = $this->keyStore->getKeyUsage($context->apiKey->id, $today, $today);

            if ($dailyUsage->costUsd >= $context->resolvedRules->budgetPerDay) {
                throw GatewayException::budgetExceeded($context->apiKey->id, 'daily', $context->resolvedRules->budgetPerDay, $dailyUsage->costUsd);
            }
        }

        if (null !== $context->resolvedRules->budgetPerMonth) {
            $monthStart = date('Y-m-01');
            $monthEnd = date('Y-m-t');

            $monthlyUsage = $this->keyStore->getKeyUsage($context->apiKey->id, $monthStart, $monthEnd);

            if ($monthlyUsage->costUsd >= $context->resolvedRules->budgetPerMonth) {
                throw GatewayException::budgetExceeded($context->apiKey->id, 'monthly', $context->resolvedRules->budgetPerMonth, $monthlyUsage->costUsd);
            }
        }
    }

    public function checkRateLimit(ApiKeyContext $context): void
    {
        if (null === $context->resolvedRules->rateLimitPerMinute) {
            return;
        }

        $result = $this->rateLimiter->isAllowed(
            $context->apiKey->id,
            $context->resolvedRules->rateLimitPerMinute,
        );

        if (!$result->allowed) {
            throw GatewayException::rateLimited(sprintf('key:%s', $context->apiKey->id), $result->limit, $result->resetAt);
        }
    }

    public function incrementRateLimit(ApiKeyContext $context): void
    {
        if (null === $context->resolvedRules->rateLimitPerMinute) {
            return;
        }

        $this->rateLimiter->increment($context->apiKey->id);
    }

    public function recordUsage(ApiKeyContext $context, int $tokens, float $costUsd): void
    {
        $this->keyStore->incrementKeyUsage(
            $context->apiKey->id,
            date('Y-m-d'),
            $tokens,
            $costUsd,
        );
    }
}
