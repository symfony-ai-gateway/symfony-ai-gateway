<?php

declare(strict_types=1);

namespace AIGateway\Logging;

use AIGateway\Core\NormalizedResponse;
use Doctrine\DBAL\Connection;

use function array_map;
use function bin2hex;
use function random_bytes;

final class RequestLogStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform->hasDoctrineTypeMappingFor('json')) {
            $platform->registerDoctrineTypeMapping('json', 'text');
        }
    }

    public function initializeSchema(): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['gateway_request_log'])) {
            $this->connection->executeStatement('
                CREATE TABLE gateway_request_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    key_id VARCHAR(36) DEFAULT NULL,
                    key_name VARCHAR(255) DEFAULT NULL,
                    team_id VARCHAR(36) DEFAULT NULL,
                    team_name VARCHAR(255) DEFAULT NULL,
                    model_alias VARCHAR(255) NOT NULL,
                    provider VARCHAR(255) NOT NULL,
                    prompt_tokens INTEGER NOT NULL DEFAULT 0,
                    completion_tokens INTEGER NOT NULL DEFAULT 0,
                    total_tokens INTEGER NOT NULL DEFAULT 0,
                    cost_usd REAL NOT NULL DEFAULT 0,
                    duration_ms REAL NOT NULL DEFAULT 0,
                    status_code INTEGER NOT NULL DEFAULT 200,
                    cached BOOLEAN NOT NULL DEFAULT 0,
                    error TEXT DEFAULT NULL,
                    created_at INTEGER NOT NULL
                )
            ');
            $this->connection->executeStatement('CREATE INDEX idx_reqlog_key ON gateway_request_log(key_id)');
            $this->connection->executeStatement('CREATE INDEX idx_reqlog_team ON gateway_request_log(team_id)');
            $this->connection->executeStatement('CREATE INDEX idx_reqlog_created ON gateway_request_log(created_at)');
            $this->connection->executeStatement('CREATE INDEX idx_reqlog_model ON gateway_request_log(model_alias)');
        }
    }

    public function insert(RequestLog $log, string|null $keyId = null, string|null $keyName = null, string|null $teamId = null, string|null $teamName = null): void
    {
        $this->connection->insert('gateway_request_log', [
            'key_id' => $keyId,
            'key_name' => $keyName,
            'team_id' => $teamId,
            'team_name' => $teamName,
            'model_alias' => $log->model,
            'provider' => $log->provider,
            'prompt_tokens' => $log->promptTokens,
            'completion_tokens' => $log->completionTokens,
            'total_tokens' => $log->totalTokens,
            'cost_usd' => $log->costUsd,
            'duration_ms' => $log->durationMs,
            'status_code' => $log->statusCode,
            'cached' => $log->cached ? 1 : 0,
            'error' => $log->error,
            'created_at' => time(),
        ]);
    }

    /**
     * Convenience method to log a NormalizedResponse directly from Gateway.
     * Stores only analytics metadata — NO request/response content.
     */
    public function logResponse(
        NormalizedResponse $response,
        string $modelAlias,
        float $durationMs,
        string|null $keyId = null,
        string|null $keyName = null,
        string|null $teamId = null,
        string|null $teamName = null,
        string|null $error = null,
    ): void {
        $this->insert(
            log: new RequestLog(
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
            ),
            keyId: $keyId,
            keyName: $keyName,
            teamId: $teamId,
            teamName: $teamName,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getKeyLogs(string $keyId, int $limit = 50, int $offset = 0): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM gateway_request_log WHERE key_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$keyId, $limit, $offset],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTeamLogs(string $teamId, int $limit = 50, int $offset = 0): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM gateway_request_log WHERE team_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$teamId, $limit, $offset],
        );
    }

    /**
     * @return array{requests: int, tokens: int, cost: float, errors: int}
     */
    public function getKeyStats(string $keyId): array
    {
        return $this->aggregate('key_id = ?', [$keyId]);
    }

    /**
     * @return array{requests: int, tokens: int, cost: float, errors: int}
     */
    public function getTeamStats(string $teamId): array
    {
        return $this->aggregate('team_id = ?', [$teamId]);
    }

    /**
     * @return array{requests: int, tokens: int, cost: float, errors: int}
     */
    public function getGlobalStats(): array
    {
        return $this->aggregate('1 = 1', []);
    }

    /**
     * @return list<array{model_alias: string, requests: int, tokens: int, cost: float}>
     */
    public function getTopModels(int $limit = 10): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT model_alias, COUNT(*) as requests, SUM(total_tokens) as tokens, SUM(cost_usd) as cost
             FROM gateway_request_log GROUP BY model_alias ORDER BY cost DESC LIMIT ?',
            [$limit],
        );
    }

    /**
     * @return list<array{provider: string, requests: int, cost: float}>
     */
    public function getTopProviders(int $limit = 10): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT provider, COUNT(*) as requests, SUM(cost_usd) as cost
             FROM gateway_request_log GROUP BY provider ORDER BY cost DESC LIMIT ?',
            [$limit],
        );
    }

    /**
     * @return list<array{key_id: string, key_name: string, requests: int, cost: float}>
     */
    public function getTopKeys(int $limit = 10): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT key_id, key_name, COUNT(*) as requests, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE key_id IS NOT NULL GROUP BY key_id ORDER BY cost DESC LIMIT ?',
            [$limit],
        );
    }

    /**
     * @return list<array{team_id: string, team_name: string, requests: int, cost: float}>
     */
    public function getTopTeams(int $limit = 10): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT team_id, team_name, COUNT(*) as requests, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE team_id IS NOT NULL GROUP BY team_id ORDER BY cost DESC LIMIT ?',
            [$limit],
        );
    }

    /**
     * @return list<array{date: string, requests: int, tokens: int, cost: float}>
     */
    public function getDailyUsage(int $days = 30): array
    {
        $since = time() - ($days * 86400);

        return $this->connection->fetchAllAssociative(
            "SELECT date(created_at, 'unixepoch') as date, COUNT(*) as requests, SUM(total_tokens) as tokens, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE created_at >= ? GROUP BY date ORDER BY date ASC",
            [$since],
        );
    }

    /**
     * @return list<array{model_alias: string, requests: int, tokens: int, cost: float}>
     */
    public function getKeyModelBreakdown(string $keyId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT model_alias, COUNT(*) as requests, SUM(total_tokens) as tokens, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE key_id = ? GROUP BY model_alias ORDER BY cost DESC',
            [$keyId],
        );
    }

    /**
     * @return list<array{key_id: string, key_name: string, requests: int, tokens: int, cost: float}>
     */
    public function getTeamMemberUsage(string $teamId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT key_id, key_name, COUNT(*) as requests, SUM(total_tokens) as tokens, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE team_id = ? GROUP BY key_id ORDER BY cost DESC',
            [$teamId],
        );
    }

    /**
     * @return array{requests: int, tokens: int, cost: float, errors: int}
     */
    private function aggregate(string $where, array $params): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*) as requests, COALESCE(SUM(total_tokens), 0) as tokens, COALESCE(SUM(cost_usd), 0) as cost, SUM(CASE WHEN status_code >= 400 OR error IS NOT NULL THEN 1 ELSE 0 END) as errors FROM gateway_request_log WHERE {$where}",
            $params,
        );

        return [
            'requests' => (int) ($row['requests'] ?? 0),
            'tokens' => (int) ($row['tokens'] ?? 0),
            'cost' => (float) ($row['cost'] ?? 0),
            'errors' => (int) ($row['errors'] ?? 0),
        ];
    }
}
