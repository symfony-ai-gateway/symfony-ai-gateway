<?php

declare(strict_types=1);

namespace AIGateway\Core;

use AIGateway\Provider\ProviderRequest;
use AIGateway\Provider\StreamingProviderAdapterInterface;
use Generator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;
use function strlen;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class StreamProxy
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        LoggerInterface|null $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @return Generator<int, NormalizedStreamChunk>
     */
    public function proxy(
        ProviderRequest $request,
        StreamingProviderAdapterInterface $adapter,
        string $requestedModel,
    ): Generator {
        $this->logger->debug(sprintf(
            '[AIGateway] Streaming %s %s',
            $request->method,
            $request->url,
        ));

        $response = $this->httpClient->request($request->method, $request->url, [
            'headers' => $request->headers,
            'body' => $request->body,
            'timeout' => $request->timeoutSeconds,
        ]);

        $accumulatedContent = '';
        $chunkIndex = 0;

        foreach ($this->httpClient->stream($response) as $chunk) {
            if (!$chunk->isLast()) {
                $chunkString = $chunk->getContent();

                if ('' === $chunkString) {
                    continue;
                }

                $lines = explode("\n", $chunkString);

                foreach ($lines as $line) {
                    $line = trim($line);

                    if ('' === $line) {
                        continue;
                    }

                    if ($adapter->isStreamDone($line)) {
                        $this->logger->debug('[AIGateway] Stream complete', [
                            'chunks' => $chunkIndex,
                            'bytes' => strlen($accumulatedContent),
                        ]);

                        break 2;
                    }

                    $normalized = $adapter->translateStreamChunk($line, $requestedModel);

                    if (null === $normalized) {
                        continue;
                    }

                    $accumulatedContent .= $normalized->delta;
                    ++$chunkIndex;

                    yield $chunkIndex => $normalized;
                }

                continue;
            }
        }
    }
}
