<?php

declare(strict_types=1);

namespace AIGateway\Config;

use Doctrine\DBAL\Connection;

use function json_decode;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

final class ConfigStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function initializeSchema(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $tables = $schemaManager->listTableNames();

        if (!in_array('gateway_providers', $tables, true)) {
            $this->connection->executeStatement('
                CREATE TABLE gateway_providers (
                    name VARCHAR(100) PRIMARY KEY,
                    format VARCHAR(20) NOT NULL DEFAULT \'openai\',
                    api_key VARCHAR(500) NOT NULL DEFAULT \'\',
                    base_url VARCHAR(500) DEFAULT NULL,
                    completions_path VARCHAR(200) DEFAULT \'/v1/chat/completions\',
                    enabled BOOLEAN NOT NULL DEFAULT 1,
                    created_at INTEGER NOT NULL
                )
            ');
        }

        if (!in_array('gateway_models', $tables, true)) {
            $this->connection->executeStatement('
                CREATE TABLE gateway_models (
                    alias VARCHAR(100) PRIMARY KEY,
                    provider_name VARCHAR(100) NOT NULL,
                    model VARCHAR(200) NOT NULL,
                    pricing_input REAL NOT NULL DEFAULT 0.0,
                    pricing_output REAL NOT NULL DEFAULT 0.0,
                    enabled BOOLEAN NOT NULL DEFAULT 1,
                    created_at INTEGER NOT NULL,
                    FOREIGN KEY (provider_name) REFERENCES gateway_providers(name)
                )
            ');
        }
    }

    // ── Providers ──

    /**
     * @return list<array{name: string, format: string, api_key: string, base_url: string|null, completions_path: string, enabled: bool}>
     */
    public function listProviders(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT name, format, api_key, base_url, completions_path, enabled FROM gateway_providers ORDER BY name'
        );

        return array_values(array_map(fn (array $row): array => [
            'name' => (string) $row['name'],
            'format' => (string) $row['format'],
            'api_key' => (string) $row['api_key'],
            'base_url' => null !== $row['base_url'] ? (string) $row['base_url'] : null,
            'completions_path' => (string) $row['completions_path'],
            'enabled' => (bool) $row['enabled'],
        ], $rows));
    }

    /**
     * @return array{name: string, format: string, api_key: string, base_url: string|null, completions_path: string, enabled: bool}|null
     */
    public function getProvider(string $name): array|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT name, format, api_key, base_url, completions_path, enabled FROM gateway_providers WHERE name = ?',
            [$name]
        );

        if (false === $row) {
            return null;
        }

        return [
            'name' => (string) $row['name'],
            'format' => (string) $row['format'],
            'api_key' => (string) $row['api_key'],
            'base_url' => null !== $row['base_url'] ? (string) $row['base_url'] : null,
            'completions_path' => (string) $row['completions_path'],
            'enabled' => (bool) $row['enabled'],
        ];
    }

    public function saveProvider(string $name, string $format, string $apiKey, string|null $baseUrl, string $completionsPath = '/v1/chat/completions'): void
    {
        $existing = $this->getProvider($name);

        if (null !== $existing) {
            $this->connection->update('gateway_providers', [
                'format' => $format,
                'api_key' => $apiKey,
                'base_url' => $baseUrl,
                'completions_path' => $completionsPath,
            ], ['name' => $name]);
        } else {
            $this->connection->insert('gateway_providers', [
                'name' => $name,
                'format' => $format,
                'api_key' => $apiKey,
                'base_url' => $baseUrl,
                'completions_path' => $completionsPath,
                'enabled' => 1,
                'created_at' => time(),
            ]);
        }
    }

    public function deleteProvider(string $name): void
    {
        $this->connection->delete('gateway_models', ['provider_name' => $name]);
        $this->connection->delete('gateway_providers', ['name' => $name]);
    }

    public function toggleProvider(string $name, bool $enabled): void
    {
        $this->connection->update('gateway_providers', ['enabled' => $enabled ? 1 : 0], ['name' => $name]);
    }

    // ── Models ──

    /**
     * @return list<array{alias: string, provider_name: string, model: string, pricing_input: float, pricing_output: float, enabled: bool}>
     */
    public function listModels(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT alias, provider_name, model, pricing_input, pricing_output, enabled FROM gateway_models ORDER BY alias'
        );

        return array_values(array_map(fn (array $row): array => [
            'alias' => (string) $row['alias'],
            'provider_name' => (string) $row['provider_name'],
            'model' => (string) $row['model'],
            'pricing_input' => (float) $row['pricing_input'],
            'pricing_output' => (float) $row['pricing_output'],
            'enabled' => (bool) $row['enabled'],
        ], $rows));
    }

    /**
     * @return array{alias: string, provider_name: string, model: string, pricing_input: float, pricing_output: float, enabled: bool}|null
     */
    public function getModel(string $alias): array|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT alias, provider_name, model, pricing_input, pricing_output, enabled FROM gateway_models WHERE alias = ?',
            [$alias]
        );

        if (false === $row) {
            return null;
        }

        return [
            'alias' => (string) $row['alias'],
            'provider_name' => (string) $row['provider_name'],
            'model' => (string) $row['model'],
            'pricing_input' => (float) $row['pricing_input'],
            'pricing_output' => (float) $row['pricing_output'],
            'enabled' => (bool) $row['enabled'],
        ];
    }

    public function saveModel(string $alias, string $providerName, string $model, float $pricingInput = 0.0, float $pricingOutput = 0.0): void
    {
        $existing = $this->getModel($alias);

        if (null !== $existing) {
            $this->connection->update('gateway_models', [
                'provider_name' => $providerName,
                'model' => $model,
                'pricing_input' => $pricingInput,
                'pricing_output' => $pricingOutput,
            ], ['alias' => $alias]);
        } else {
            $this->connection->insert('gateway_models', [
                'alias' => $alias,
                'provider_name' => $providerName,
                'model' => $model,
                'pricing_input' => $pricingInput,
                'pricing_output' => $pricingOutput,
                'enabled' => 1,
                'created_at' => time(),
            ]);
        }
    }

    public function deleteModel(string $alias): void
    {
        $this->connection->delete('gateway_models', ['alias' => $alias]);
    }

    public function toggleModel(string $alias, bool $enabled): void
    {
        $this->connection->update('gateway_models', ['enabled' => $enabled ? 1 : 0], ['alias' => $alias]);
    }

    /**
     * Returns models config in the same format as YAML, for ModelRegistry.
     *
     * @return array<string, array{provider: string, model: string, pricing: array{input: float, output: float}}>
     */
    public function getModelsConfig(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT alias, provider_name, model, pricing_input, pricing_output FROM gateway_models WHERE enabled = 1'
        );

        $config = [];
        foreach ($rows as $row) {
            $config[$row['alias']] = [
                'provider' => $row['provider_name'],
                'model' => $row['model'],
                'pricing' => [
                    'input' => (float) $row['pricing_input'],
                    'output' => (float) $row['pricing_output'],
                ],
            ];
        }

        return $config;
    }

    /**
     * Returns providers config for runtime factory.
     *
     * @return array<string, array{format: string, api_key: string, base_url: string|null, completions_path: string}>
     */
    public function getProvidersConfig(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT name, format, api_key, base_url, completions_path FROM gateway_providers WHERE enabled = 1'
        );

        $config = [];
        foreach ($rows as $row) {
            $config[$row['name']] = [
                'format' => $row['format'],
                'api_key' => $row['api_key'],
                'base_url' => $row['base_url'],
                'completions_path' => $row['completions_path'],
            ];
        }

        return $config;
    }
}
