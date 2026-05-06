<?php

declare(strict_types=1);

namespace PhiGateway\Provider;

/**
 * HTTP request ready to be sent to a specific provider.
 */
final readonly class ProviderRequest
{
    /**
     * @param string $url Full URL to call
     * @param string $method HTTP method (GET, POST)
     * @param array<string, string> $headers HTTP headers (auth, content-type, etc.)
     * @param string $body JSON-encoded request body
     * @param int $timeoutSeconds Request timeout
     */
    public function __construct(
        public string $url,
        public string $method = 'POST',
        public array $headers = [],
        public string $body = '',
        public int $timeoutSeconds = 30,
    ) {
    }
}
