<?php

declare(strict_types=1);

namespace AIGateway\Config;

use AIGateway\Exception\GatewayException;

/**
 * Resolves model aliases to their full provider + model configuration.
 */
class ModelRegistry
{
    /** @var array<string, ModelResolution> */
    private array $models = [];

    /** @var array<string, string> alias → model alias */
    private array $aliases = [];

    /**
     * @param array<string, array{provider: string, model: string, pricing?: array{input?: float, output?: float}, max_tokens?: int}> $config
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $alias => $modelConfig) {
            $pricing = new ModelPricing(
                inputPerMillion: $modelConfig['pricing']['input'] ?? 0.0,
                outputPerMillion: $modelConfig['pricing']['output'] ?? 0.0,
            );

            $this->models[$alias] = new ModelResolution(
                alias: $alias,
                provider: $modelConfig['provider'],
                model: $modelConfig['model'],
                pricing: $pricing,
                maxTokens: $modelConfig['max_tokens'] ?? 128000,
            );
        }
    }

    public function register(ModelResolution $resolution): void
    {
        $this->models[$resolution->alias] = $resolution;
    }

    public function addAlias(string $alias, string $target): void
    {
        $this->aliases[$alias] = $target;
    }

    public function resolve(string $modelAlias): ModelResolution
    {
        $resolved = $this->aliases[$modelAlias] ?? $modelAlias;

        if (!isset($this->models[$resolved])) {
            throw GatewayException::modelNotFound($modelAlias, array_keys($this->models));
        }

        return $this->models[$resolved];
    }

    public function has(string $modelAlias): bool
    {
        $resolved = $this->aliases[$modelAlias] ?? $modelAlias;

        return isset($this->models[$resolved]);
    }

    /** @return list<string> */
    public function getAvailableModels(): array
    {
        return array_keys($this->models);
    }
}
