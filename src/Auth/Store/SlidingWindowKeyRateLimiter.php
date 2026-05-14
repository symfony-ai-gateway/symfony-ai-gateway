<?php

declare(strict_types=1);

namespace AIGateway\Auth\Store;

use AIGateway\RateLimit\RateLimitResult;
use Doctrine\DBAL\Connection;

use function time;

final class SlidingWindowKeyRateLimiter
{
    private bool $schemaInitialized = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform->hasDoctrineTypeMappingFor('json')) {
            $platform->registerDoctrineTypeMapping('json', 'text');
        }
    }

    public function isAllowed(string $keyId, int $maxPerMinute): RateLimitResult
    {
        $this->ensureSchema();

        $now = time();
        $windowStart = $now - 60;

        $this->connection->executeStatement(
            "DELETE FROM gateway_rate_limits WHERE key_id = ? AND timestamp < ?",
            [$keyId, $windowStart],
        );

        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM gateway_rate_limits WHERE key_id = ? AND timestamp >= ?",
            [$keyId, $windowStart],
        );

        return new RateLimitResult(
            allowed: $count < $maxPerMinute,
            limit: $maxPerMinute,
            remaining: max(0, $maxPerMinute - $count),
            resetAt: $now + 60,
        );
    }

    public function increment(string $keyId): void
    {
        $this->ensureSchema();

        $this->connection->insert('gateway_rate_limits', [
            'key_id' => $keyId,
            'timestamp' => time(),
        ]);
    }

    private function ensureSchema(): void
    {
        if ($this->schemaInitialized) {
            return;
        }

        $this->connection->executeStatement('
            CREATE TABLE IF NOT EXISTS gateway_rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key_id VARCHAR(64) NOT NULL,
                timestamp INTEGER NOT NULL
            )
        ');

        try {
            $this->connection->executeStatement('
                CREATE INDEX IF NOT EXISTS idx_rate_key_ts ON gateway_rate_limits (key_id, timestamp)
            ');
        } catch (\Throwable) {
        }

        $this->schemaInitialized = true;
    }
}
