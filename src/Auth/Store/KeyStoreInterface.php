<?php

declare(strict_types=1);

namespace AIGateway\Auth\Store;

use AIGateway\Auth\Entity\ApiKey;
use AIGateway\Auth\Entity\Team;

interface KeyStoreInterface
{
    public function findKeyByHash(string $keyHash): ApiKey|null;

    public function findKeyById(string $id): ApiKey|null;

    public function findTeamById(string $id): Team|null;

    /**
     * @return list<Team> parent teams ordered from root to leaf
     */
    public function findTeamAncestry(string $teamId): array;

    public function saveKey(ApiKey $apiKey): void;

    public function saveTeam(Team $team): void;

    /**
     * @return list<ApiKey>
     */
    public function listKeys(): array;

    /**
     * @return list<Team>
     */
    public function listTeams(): array;

    public function deleteKey(string $id): void;

    public function deleteTeam(string $id): void;

    /**
     * Track spending for a key on a given date.
     */
    public function incrementKeyUsage(string $keyId, string $date, int $tokens, float $costUsd): void;

    /**
     * Get accumulated spending for a key.
     */
    public function getKeyUsage(string $keyId, string $periodStart, string $periodEnd): KeyUsage;

    public function initializeSchema(): void;
}
