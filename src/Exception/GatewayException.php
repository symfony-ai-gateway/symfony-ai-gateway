<?php

declare(strict_types=1);

namespace AIGateway\Exception;

use RuntimeException;

use function sprintf;

class GatewayException extends RuntimeException
{
    /** @param list<string> $available */
    public static function modelNotFound(string $model, array $available = []): self
    {
        $list = [] !== $available ? ' Available models: '.implode(', ', $available) : '';

        return new self(sprintf('Model "%s" not found.%s', $model, $list));
    }

    public static function providerNotFound(string $provider): self
    {
        return new self(sprintf('Provider "%s" not found.', $provider));
    }

    public static function providerError(string $provider, int $statusCode, string $message): self
    {
        return new self(
            sprintf('Provider "%s" returned error %d: %s', $provider, $statusCode, $message),
            $statusCode,
        );
    }

    public static function allProvidersFailed(string $requestedModel): self
    {
        return new self(sprintf('All providers failed for model "%s".', $requestedModel));
    }

    public static function invalidRequest(string $message): self
    {
        return new self(sprintf('Invalid request: %s', $message));
    }

    public static function rateLimited(string $scope, int $limit, int $resetAt): self
    {
        return new self(
            sprintf('Rate limit exceeded for "%s" (limit: %d, resets at: %d).', $scope, $limit, $resetAt),
            429,
        );
    }

    public static function authenticationFailed(string $message): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 403);
    }

    public static function budgetExceeded(string $keyId, string $period, float $budget, float $used): self
    {
        return new self(
            sprintf('Budget exceeded for key "%s": %s limit $%.4f, used $%.4f.', $keyId, $period, $budget, $used),
            429,
        );
    }
}
