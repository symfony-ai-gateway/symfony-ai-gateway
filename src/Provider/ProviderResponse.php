<?php

declare(strict_types=1);

namespace AIGateway\Provider;

/**
 * Raw HTTP response received from a provider.
 */
final readonly class ProviderResponse
{
    /**
     * @param int                   $statusCode HTTP status code
     * @param array<string, string> $headers    Response headers
     * @param string                $body       Raw response body
     */
    public function __construct(
        public int $statusCode,
        public array $headers = [],
        public string $body = '',
    ) {
    }
}
