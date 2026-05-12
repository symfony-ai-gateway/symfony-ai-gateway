<?php

declare(strict_types=1);

use AIGateway\Auth\Entity\ApiKey;
use AIGateway\Auth\Entity\KeyRules;
use AIGateway\Auth\Entity\Team;
use AIGateway\Auth\Store\DbalKeyStore;
use AIGateway\Standalone\StandaloneKernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

require __DIR__.'/../vendor/autoload.php';

$envFile = __DIR__.'/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ('' !== $key && !isset($_SERVER[$key]) && !isset($_ENV[$key])) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

$projectDir = dirname(__DIR__);
$connection = DriverManager::getConnection([
    'driver' => 'pdo_sqlite',
    'path' => $projectDir.'/data/auth.db',
]);
$keyStore = new DbalKeyStore($connection);

$keyStore->initializeSchema();
echo "Schema initialized.\n";

$teamId = bin2hex(random_bytes(16));
$team = new Team(
    id: $teamId,
    name: 'Etixio',
    parentId: null,
    rules: new KeyRules(
        budgetPerDay: null,
        budgetPerMonth: null,
        rateLimitPerMinute: null,
        models: ['qwen3_5_plus', 'glm_5', 'deepseek_v4_flash'],
    ),
    createdAt: time(),
);
$keyStore->saveTeam($team);
echo "Team 'Etixio' created: {$teamId}\n";

$rawToken = 'aig_'.bin2hex(random_bytes(24));
$keyHash = hash('sha256', $rawToken);
$prefix = substr($rawToken, 0, 8);
$keyId = bin2hex(random_bytes(16));

$apiKey = new ApiKey(
    id: $keyId,
    name: 'Mathieu',
    keyHash: $keyHash,
    tokenPrefix: $prefix,
    teamId: $teamId,
    overrides: new KeyRules(
        budgetPerDay: null,
        budgetPerMonth: null,
        rateLimitPerMinute: null,
        models: ['qwen3_5_plus', 'deepseek_v4_flash'],
    ),
    enabled: true,
    expiresAt: null,
    createdAt: time(),
);
$keyStore->saveKey($apiKey);

echo "\n========================================\n";
echo "API Key for Mathieu:\n";
echo "{$rawToken}\n";
echo "========================================\n";
echo "Team: Etixio (all 3 models)\n";
echo "Key: Mathieu (qwen3.5-plus + deepseek-v4-flash only)\n";
echo "No budget limits.\n";
