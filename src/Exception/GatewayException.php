<?php

declare(strict_types=1);

namespace PhiGateway\Exception;

class GatewayException extends \RuntimeException
{
    /** @param list<string> $available */
    public static function modelNotFound(string $model, array $available = []): self
    {
        $list = $available !== [] ? ' Available models: ' . implode(', ', $available) : '';

        return new self(\sprintf('Model "%s" not found.%s', $model, $list));
    }

    public static function providerNotFound(string $provider): self
    {
        return new self(\sprintf('Provider "%s" not found.', $provider));
    }

    public static function providerError(string $provider, int $statusCode, string $message): self
    {
        return new self(
            \sprintf('Provider "%s" returned error %d: %s', $provider, $statusCode, $message),
            $statusCode,
        );
    }

    public static function allProvidersFailed(string $requestedModel): self
    {
        return new self(\sprintf('All providers failed for model "%s".', $requestedModel));
    }

    public static function invalidRequest(string $message): self
    {
        return new self(\sprintf('Invalid request: %s', $message));
    }
}
