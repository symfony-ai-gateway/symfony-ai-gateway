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

    // ── Provider detail queries ──────────────────────────────────────────────

    /**
     * @return array{requests: int, tokens: int, cost: float, errors: int}
     */
    public function getProviderStats(string $provider): array
    {
        return $this->aggregate('provider = ?', [$provider]);
    }

    /**
     * @return list<array{model_alias: string, requests: int, tokens: int, cost: float}>
     */
    public function getProviderModelBreakdown(string $provider): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT model_alias, COUNT(*) as requests, SUM(total_tokens) as tokens, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE provider = ? GROUP BY model_alias ORDER BY cost DESC',
            [$provider],
        );
    }

    /**
     * @return list<array{key_id: string, key_name: string, requests: int, cost: float}>
     */
    public function getProviderTopKeys(string $provider, int $limit = 10): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT key_id, key_name, COUNT(*) as requests, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE provider = ? AND key_id IS NOT NULL GROUP BY key_id ORDER BY cost DESC LIMIT ?',
            [$provider, $limit],
        );
    }

    /**
     * @return list<array{date: string, requests: int, cost: float}>
     */
    public function getProviderDailyUsage(string $provider, int $days = 30): array
    {
        $since = time() - ($days * 86400);

        return $this->connection->fetchAllAssociative(
            "SELECT date(created_at, 'unixepoch') as date, COUNT(*) as requests, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE provider = ? AND created_at >= ? GROUP BY date ORDER BY date ASC",
            [$provider, $since],
        );
    }

    // ── Model detail queries ─────────────────────────────────────────────────

    /**
     * @return array{requests: int, tokens: int, cost: float, errors: int}
     */
    public function getModelStats(string $modelAlias): array
    {
        return $this->aggregate('model_alias = ?', [$modelAlias]);
    }

    /**
     * @return list<array{team_id: string, team_name: string, requests: int, cost: float}>
     */
    public function getModelTeamBreakdown(string $modelAlias): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT team_id, team_name, COUNT(*) as requests, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE model_alias = ? AND team_id IS NOT NULL GROUP BY team_id ORDER BY cost DESC',
            [$modelAlias],
        );
    }

    /**
     * @return list<array{key_id: string, key_name: string, requests: int, tokens: int, cost: float}>
     */
    public function getModelKeyBreakdown(string $modelAlias): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT key_id, key_name, COUNT(*) as requests, SUM(total_tokens) as tokens, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE model_alias = ? AND key_id IS NOT NULL GROUP BY key_id ORDER BY cost DESC',
            [$modelAlias],
        );
    }

    /**
     * @return list<array{date: string, requests: int, cost: float}>
     */
    public function getModelDailyUsage(string $modelAlias, int $days = 30): array
    {
        $since = time() - ($days * 86400);

        return $this->connection->fetchAllAssociative(
            "SELECT date(created_at, 'unixepoch') as date, COUNT(*) as requests, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE model_alias = ? AND created_at >= ? GROUP BY date ORDER BY date ASC",
            [$modelAlias, $since],
        );
    }

    // ── Filtered daily usage (fix: was using global data) ────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    public function getModelLogs(string $modelAlias, int $limit = 50, int $offset = 0): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM gateway_request_log WHERE model_alias = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$modelAlias, $limit, $offset],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getProviderLogs(string $provider, int $limit = 50, int $offset = 0): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM gateway_request_log WHERE provider = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$provider, $limit, $offset],
        );
    }

    /**
     * @return list<array{date: string, model_alias: string, requests: int}>
     */
    public function getKeyDailyUsageByModel(string $keyId, int $days = 30): array
    {
        $since = time() - ($days * 86400);

        return $this->connection->fetchAllAssociative(
            "SELECT date(created_at, 'unixepoch') as date, model_alias, CAST(COUNT(*) AS INTEGER) as requests
             FROM gateway_request_log WHERE key_id = ? AND created_at >= ?
             GROUP BY date, model_alias ORDER BY date ASC, requests DESC",
            [$keyId, $since],
        );
    }

    /**
     * @return list<array{date: string, requests: int, tokens: int, cost: float}>
     */
    public function getKeyDailyUsage(string $keyId, int $days = 30): array
    {
        $since = time() - ($days * 86400);

        return $this->connection->fetchAllAssociative(
            "SELECT date(created_at, 'unixepoch') as date, COUNT(*) as requests, SUM(total_tokens) as tokens, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE key_id = ? AND created_at >= ? GROUP BY date ORDER BY date ASC",
            [$keyId, $since],
        );
    }

    /**
     * @return list<array{date: string, requests: int, tokens: int, cost: float}>
     */
    public function getTeamDailyUsage(string $teamId, int $days = 30): array
    {
        $since = time() - ($days * 86400);

        return $this->connection->fetchAllAssociative(
            "SELECT date(created_at, 'unixepoch') as date, COUNT(*) as requests, SUM(total_tokens) as tokens, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE team_id = ? AND created_at >= ? GROUP BY date ORDER BY date ASC",
            [$teamId, $since],
        );
    }

    /**
     * @return list<array{model_alias: string, requests: int, tokens: int, cost: float}>
     */
    public function getTeamModelBreakdown(string $teamId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT model_alias, COUNT(*) as requests, SUM(total_tokens) as tokens, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE team_id = ? GROUP BY model_alias ORDER BY cost DESC',
            [$teamId],
        );
    }

    /**
     * @return list<array{provider: string, requests: int, cost: float}>
     */
    public function getTeamProviderBreakdown(string $teamId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT provider, COUNT(*) as requests, SUM(cost_usd) as cost
             FROM gateway_request_log WHERE team_id = ? GROUP BY provider ORDER BY cost DESC',
            [$teamId],
        );
    }

    // ── Log blocked requests (rate-limit, budget, auth) ──────────────────────

    /**
     * Log a request that was blocked by rate-limit, budget, or auth enforcer.
     * Stores only analytics metadata — NO request/response content.
     */
    public function logBlockedRequest(
        string $modelAlias,
        string $provider,
        int $statusCode,
        string $error,
        string|null $keyId = null,
        string|null $keyName = null,
        string|null $teamId = null,
        string|null $teamName = null,
    ): void {
        $this->insert(
            log: new RequestLog(
                id: bin2hex(random_bytes(8)),
                model: $modelAlias,
                provider: $provider,
                promptTokens: 0,
                completionTokens: 0,
                totalTokens: 0,
                costUsd: 0.0,
                durationMs: 0.0,
                cached: false,
                statusCode: $statusCode,
                error: $error,
            ),
            keyId: $keyId,
            keyName: $keyName,
            teamId: $teamId,
            teamName: $teamName,
        );
    }

    // ── Flexible request search ──────────────────────────────────────────────

    /**
     * Search requests with flexible filters. No request/response content stored.
     *
     * @param array{key_id?: string, key_name?: string, team_id?: string, team_name?: string, provider?: string, model_alias?: string, status_code_min?: int, status_code_max?: int, date_from?: int, date_to?: int, error?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int, summary: array{requests: int, tokens: int, cost: float, errors: int}}
     */
    public function searchRequests(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildSearchWhere($filters);

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM gateway_request_log WHERE {$where}",
            $params,
        );

        $rows = $total > 0
            ? $this->connection->fetchAllAssociative(
                "SELECT * FROM gateway_request_log WHERE {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?",
                [...$params, $limit, $offset],
            )
            : [];

        $summary = $this->aggregateByWhere($where, $params);

        return [
            'rows' => $rows,
            'total' => $total,
            'summary' => $summary,
        ];
    }

    /**
     * Get 9 breakdowns (tokens/cost/requests × model/key/team) for filtered requests.
     *
     * @param array{key_id?: string, key_name?: string, team_id?: string, team_name?: string, provider?: string, model_alias?: string, status_code_min?: int, status_code_max?: int, date_from?: int, date_to?: int, error?: string} $filters
     *
     * @return array{tokens_by_model: list<array{label: string, value: float}>, tokens_by_key: list<array{label: string, value: float}>, tokens_by_team: list<array{label: string, value: float}>, cost_by_model: list<array{label: string, value: float}>, cost_by_key: list<array{label: string, value: float}>, cost_by_team: list<array{label: string, value: float}>, requests_by_model: list<array{label: string, value: float}>, requests_by_key: list<array{label: string, value: float}>, requests_by_team: list<array{label: string, value: float}>}
     */
    public function getFilteredBreakdowns(array $filters = [], int $limit = 10): array
    {
        [$where, $params] = $this->buildSearchWhere($filters);

        $exec = function (string $select, string $extraWhere = '') use ($where, $params, $limit): array {
            $fullWhere = $where;
            $fullParams = $params;
            if ('' !== $extraWhere) {
                $fullWhere .= ' AND '.$extraWhere;
            }

            return $this->connection->fetchAllAssociative(
                "SELECT {$select} FROM gateway_request_log WHERE {$fullWhere} GROUP BY label ORDER BY value DESC LIMIT ?",
                [...$fullParams, $limit],
            );
        };

        $tokensByModel = $exec(
            "COALESCE(NULLIF(model_alias, ''), '(unknown)') as label, CAST(SUM(total_tokens) AS REAL) as value",
        );
        $tokensByKey = $exec(
            "COALESCE(NULLIF(key_name, ''), '(unknown)') as label, CAST(SUM(total_tokens) AS REAL) as value",
            'key_id IS NOT NULL',
        );
        $tokensByTeam = $exec(
            "COALESCE(NULLIF(team_id, ''), '(unknown)') as label, CAST(SUM(total_tokens) AS REAL) as value",
            'team_id IS NOT NULL',
        );

        $costByModel = $exec(
            "COALESCE(NULLIF(model_alias, ''), '(unknown)') as label, CAST(SUM(cost_usd) AS REAL) as value",
        );
        $costByKey = $exec(
            "COALESCE(NULLIF(key_name, ''), '(unknown)') as label, CAST(SUM(cost_usd) AS REAL) as value",
            'key_id IS NOT NULL',
        );
        $costByTeam = $exec(
            "COALESCE(NULLIF(team_id, ''), '(unknown)') as label, CAST(SUM(cost_usd) AS REAL) as value",
            'team_id IS NOT NULL',
        );

        $requestsByModel = $exec(
            "COALESCE(NULLIF(model_alias, ''), '(unknown)') as label, CAST(COUNT(*) AS REAL) as value",
        );
        $requestsByKey = $exec(
            "COALESCE(NULLIF(key_name, ''), '(unknown)') as label, CAST(COUNT(*) AS REAL) as value",
            'key_id IS NOT NULL',
        );
        $requestsByTeam = $exec(
            "COALESCE(NULLIF(team_id, ''), '(unknown)') as label, CAST(COUNT(*) AS REAL) as value",
            'team_id IS NOT NULL',
        );

        return [
            'tokens_by_model' => $tokensByModel,
            'tokens_by_key' => $tokensByKey,
            'tokens_by_team' => $tokensByTeam,
            'cost_by_model' => $costByModel,
            'cost_by_key' => $costByKey,
            'cost_by_team' => $costByTeam,
            'requests_by_model' => $requestsByModel,
            'requests_by_key' => $requestsByKey,
            'requests_by_team' => $requestsByTeam,
        ];
    }

    /**
     * Build WHERE clause and params from filters.
     *
     * @param array{key_id?: string, key_name?: string, team_id?: string, team_name?: string, provider?: string, model_alias?: string, status_code_min?: int, status_code_max?: int, date_from?: int, date_to?: int, error?: string} $filters
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildSearchWhere(array $filters): array
    {
        $where = '1 = 1';
        $params = [];

        if (!empty($filters['key_id'])) {
            $where .= ' AND key_id = ?';
            $params[] = $filters['key_id'];
        }
        if (!empty($filters['key_name'])) {
            $where .= ' AND key_name LIKE ?';
            $params[] = '%'.$filters['key_name'].'%';
        }
        if (!empty($filters['team_id'])) {
            $where .= ' AND team_id = ?';
            $params[] = $filters['team_id'];
        }
        if (!empty($filters['team_name'])) {
            $where .= ' AND team_name LIKE ?';
            $params[] = '%'.$filters['team_name'].'%';
        }
        if (!empty($filters['provider'])) {
            $where .= ' AND provider = ?';
            $params[] = $filters['provider'];
        }
        if (!empty($filters['model_alias'])) {
            $where .= ' AND model_alias = ?';
            $params[] = $filters['model_alias'];
        }
        if (!empty($filters['status_code_min'])) {
            $where .= ' AND status_code >= ?';
            $params[] = $filters['status_code_min'];
        }
        if (!empty($filters['status_code_max'])) {
            $where .= ' AND status_code <= ?';
            $params[] = $filters['status_code_max'];
        }
        if (!empty($filters['date_from'])) {
            $where .= ' AND created_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= ' AND created_at <= ?';
            $params[] = $filters['date_to'];
        }
        if (isset($filters['error'])) {
            if ('' === $filters['error']) {
                $where .= ' AND (error IS NULL OR error = ?)';
                $params[] = '';
            } else {
                $where .= ' AND error LIKE ?';
                $params[] = '%'.$filters['error'].'%';
            }
        }

        return [$where, $params];
    }

    /**
     * @return array{requests: int, tokens: int, cost: float, errors: int}
     */
    private function aggregateByWhere(string $where, array $params): array
    {
        return $this->aggregate($where, $params);
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
