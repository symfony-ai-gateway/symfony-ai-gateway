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

        $schemaManager = $this->connection->createSchemaManager();
        $schema = $schemaManager->introspectSchema();

        if (!$schema->hasTable('gateway_rate_limits')) {
            $table = $schema->createTable('gateway_rate_limits');
            $table->addColumn('id', 'integer', ['autoincrement' => true]);
            $table->addColumn('key_id', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('timestamp', 'integer', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['key_id', 'timestamp'], 'idx_rate_key_ts');

            $schemaManager->createTable($table);
        }

        $this->schemaInitialized = true;
    }
}
