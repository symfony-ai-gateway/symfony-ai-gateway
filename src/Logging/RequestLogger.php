<?php

declare(strict_types=1);

namespace AIGateway\Logging;

use AIGateway\Core\NormalizedResponse;

use function bin2hex;
use function count;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function random_bytes;
use function sprintf;

final class RequestLogger
{
    private LoggerInterface $logger;

    /** @var list<RequestLog> */
    private array $logs = [];

    private int $maxLogs;

    public function __construct(
        LoggerInterface|null $logger = null,
        int $maxLogs = 1000,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->maxLogs = $maxLogs;
    }

    public function log(
        NormalizedResponse $response,
        string $modelAlias,
        float $durationMs,
        string|null $error = null,
    ): RequestLog {
        $entry = new RequestLog(
            id: bin2hex(random_bytes(8)),
            model: $modelAlias,
            provider: $response->provider,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            totalTokens: $response->usage->totalTokens,
            costUsd: $response->costUsd,
            durationMs: $durationMs,
            cached: $response->cacheHit,
            statusCode: $response->statusCode,
            error: $error,
        );

        $this->logs[] = $entry;

        if (count($this->logs) > $this->maxLogs) {
            array_shift($this->logs);
        }

        $this->logger->info(sprintf(
            '[AIGateway] %s %s %dms %dtok $%.6f%s%s',
            $entry->provider,
            $entry->model,
            (int) $entry->durationMs,
            $entry->totalTokens,
            $entry->costUsd,
            $entry->cached ? ' CACHED' : '',
            null !== $entry->error ? sprintf(' ERROR: %s', $entry->error) : '',
        ));

        return $entry;
    }

    /**
     * @return list<RequestLog>
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    public function clear(): void
    {
        $this->logs = [];
    }

    public function getTotalRequests(): int
    {
        return count($this->logs);
    }

    public function getTotalErrors(): int
    {
        $count = 0;

        foreach ($this->logs as $log) {
            if (null !== $log->error || $log->statusCode >= 400) {
                ++$count;
            }
        }

        return $count;
    }

    public function getAverageDurationMs(): float
    {
        if ([] === $this->logs) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($this->logs as $log) {
            $total += $log->durationMs;
        }

        return $total / count($this->logs);
    }
}
