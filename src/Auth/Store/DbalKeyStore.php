<?php

declare(strict_types=1);

namespace AIGateway\Auth\Store;

use AIGateway\Auth\Entity\ApiKey;
use AIGateway\Auth\Entity\KeyRules;
use AIGateway\Auth\Entity\Team;
use Doctrine\DBAL\Connection;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class DbalKeyStore implements KeyStoreInterface
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

        if (!$schemaManager->tablesExist(['ai_gateway_teams'])) {
            $this->connection->executeStatement('
                CREATE TABLE ai_gateway_teams (
                    id VARCHAR(36) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    parent_id VARCHAR(36) DEFAULT NULL,
                    rules JSON NOT NULL,
                    created_at INTEGER NOT NULL
                )
            ');
        }

        if (!$schemaManager->tablesExist(['ai_gateway_keys'])) {
            $this->connection->executeStatement('
                CREATE TABLE ai_gateway_keys (
                    id VARCHAR(36) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    key_hash VARCHAR(64) NOT NULL UNIQUE,
                    token_prefix VARCHAR(16) NOT NULL,
                    team_id VARCHAR(36) DEFAULT NULL,
                    overrides JSON DEFAULT NULL,
                    enabled BOOLEAN NOT NULL DEFAULT 1,
                    expires_at INTEGER DEFAULT NULL,
                    created_at INTEGER NOT NULL
                )
            ');
        }

        if (!$schemaManager->tablesExist(['ai_gateway_key_usage'])) {
            $this->connection->executeStatement('
                CREATE TABLE ai_gateway_key_usage (
                    key_id VARCHAR(36) NOT NULL,
                    date DATE NOT NULL,
                    requests INTEGER NOT NULL DEFAULT 0,
                    tokens INTEGER NOT NULL DEFAULT 0,
                    cost_usd REAL NOT NULL DEFAULT 0,
                    PRIMARY KEY (key_id, date)
                )
            ');
        }
    }

    public function findKeyByHash(string $keyHash): ApiKey|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ai_gateway_keys WHERE key_hash = ? AND enabled = 1',
            [$keyHash],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrateKey($row);
    }

    public function findKeyById(string $id): ApiKey|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ai_gateway_keys WHERE id = ?',
            [$id],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrateKey($row);
    }

    public function findTeamById(string $id): Team|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ai_gateway_teams WHERE id = ?',
            [$id],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrateTeam($row);
    }

    public function findTeamAncestry(string $teamId): array
    {
        $ancestry = [];
        $currentId = $teamId;

        while (null !== $currentId) {
            $team = $this->findTeamById($currentId);

            if (null === $team) {
                break;
            }

            $ancestry[] = $team;
            $currentId = $team->parentId;
        }

        return array_reverse($ancestry);
    }

    public function saveKey(ApiKey $apiKey): void
    {
        $overrides = null !== $apiKey->overrides ? json_encode($this->rulesToArray($apiKey->overrides), JSON_THROW_ON_ERROR) : null;

        $this->connection->executeStatement(
            'INSERT OR REPLACE INTO ai_gateway_keys (id, name, key_hash, token_prefix, team_id, overrides, enabled, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$apiKey->id, $apiKey->name, $apiKey->keyHash, $apiKey->tokenPrefix, $apiKey->teamId, $overrides, (int) $apiKey->enabled, $apiKey->expiresAt, $apiKey->createdAt],
        );
    }

    public function saveTeam(Team $team): void
    {
        $this->connection->executeStatement(
            'INSERT OR REPLACE INTO ai_gateway_teams (id, name, parent_id, rules, created_at) VALUES (?, ?, ?, ?, ?)',
            [$team->id, $team->name, $team->parentId, json_encode($this->rulesToArray($team->rules), JSON_THROW_ON_ERROR), $team->createdAt],
        );
    }

    public function listKeys(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM ai_gateway_keys ORDER BY created_at DESC');

        return array_map($this->hydrateKey(...), $rows);
    }

    public function listTeams(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM ai_gateway_teams ORDER BY name');

        return array_map($this->hydrateTeam(...), $rows);
    }

    public function deleteKey(string $id): void
    {
        $this->connection->executeStatement('DELETE FROM ai_gateway_keys WHERE id = ?', [$id]);
        $this->connection->executeStatement('DELETE FROM ai_gateway_key_usage WHERE key_id = ?', [$id]);
    }

    public function deleteTeam(string $id): void
    {
        $this->connection->executeStatement('DELETE FROM ai_gateway_teams WHERE id = ?', [$id]);
    }

    public function incrementKeyUsage(string $keyId, string $date, int $tokens, float $costUsd): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ai_gateway_key_usage (key_id, date, requests, tokens, cost_usd) VALUES (?, ?, 1, ?, ?)
             ON CONFLICT(key_id, date) DO UPDATE SET requests = requests + 1, tokens = tokens + ?, cost_usd = cost_usd + ?',
            [$keyId, $date, $tokens, $costUsd, $tokens, $costUsd],
        );
    }

    public function getKeyUsage(string $keyId, string $periodStart, string $periodEnd): KeyUsage
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(requests), 0) as requests, COALESCE(SUM(tokens), 0) as tokens, COALESCE(SUM(cost_usd), 0) as cost_usd FROM ai_gateway_key_usage WHERE key_id = ? AND date >= ? AND date <= ?',
            [$keyId, $periodStart, $periodEnd],
        );

        if (false === $row) {
            return new KeyUsage();
        }

        return new KeyUsage(
            requests: (int) $row['requests'],
            tokens: (int) $row['tokens'],
            costUsd: (float) $row['cost_usd'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateKey(array $row): ApiKey
    {
        $overrides = null;

        if (null !== $row['overrides']) {
            $data = json_decode((string) $row['overrides'], true, 512, JSON_THROW_ON_ERROR);
            $overrides = $this->arrayToRules($data);
        }

        return new ApiKey(
            id: (string) $row['id'],
            name: (string) $row['name'],
            keyHash: (string) $row['key_hash'],
            tokenPrefix: (string) $row['token_prefix'],
            teamId: $row['team_id'] ? (string) $row['team_id'] : null,
            overrides: $overrides,
            enabled: (bool) $row['enabled'],
            expiresAt: $row['expires_at'] ? (int) $row['expires_at'] : null,
            createdAt: (int) $row['created_at'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTeam(array $row): Team
    {
        $data = json_decode((string) $row['rules'], true, 512, JSON_THROW_ON_ERROR);

        return new Team(
            id: (string) $row['id'],
            name: (string) $row['name'],
            parentId: $row['parent_id'] ? (string) $row['parent_id'] : null,
            rules: $this->arrayToRules($data),
            createdAt: (int) $row['created_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesToArray(KeyRules $rules): array
    {
        return array_filter([
            'budget_per_day' => $rules->budgetPerDay,
            'budget_per_month' => $rules->budgetPerMonth,
            'rate_limit_per_minute' => $rules->rateLimitPerMinute,
            'models' => $rules->models,
        ], static fn (mixed $v): bool => null !== $v);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function arrayToRules(array $data): KeyRules
    {
        return new KeyRules(
            budgetPerDay: ($data['budget_per_day'] ?? null) !== null ? (float) $data['budget_per_day'] : null,
            budgetPerMonth: ($data['budget_per_month'] ?? null) !== null ? (float) $data['budget_per_month'] : null,
            rateLimitPerMinute: ($data['rate_limit_per_minute'] ?? null) !== null ? (int) $data['rate_limit_per_minute'] : null,
            models: ($data['models'] ?? null) !== null ? $data['models'] : null,
        );
    }
}
