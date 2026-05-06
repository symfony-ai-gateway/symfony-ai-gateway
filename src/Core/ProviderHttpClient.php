<?php

declare(strict_types=1);

namespace PhiGateway\Core;

use PhiGateway\Provider\ProviderRequest;
use PhiGateway\Provider\ProviderResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;
use function strlen;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ProviderHttpClient
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        LoggerInterface|null $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function send(ProviderRequest $request): ProviderResponse
    {
        $this->logger->debug(sprintf(
            '[PhiGateway] HTTP %s %s',
            $request->method,
            $request->url,
        ));

        $response = $this->httpClient->request($request->method, $request->url, [
            'headers' => $request->headers,
            'body' => $request->body,
            'timeout' => $request->timeoutSeconds,
        ]);

        $statusCode = $response->getStatusCode();
        $headers = $response->getHeaders(false);
        $flatHeaders = [];
        foreach ($headers as $name => $values) {
            $flatHeaders[$name] = $values[0] ?? '';
        }

        $body = $response->getContent(false);

        $this->logger->debug(sprintf(
            '[PhiGateway] Response HTTP %d (%d bytes)',
            $statusCode,
            strlen($body),
        ));

        return new ProviderResponse(
            statusCode: $statusCode,
            headers: $flatHeaders,
            body: $body,
        );
    }
}
