<?php

declare(strict_types=1);

namespace AIGateway\Router;

final readonly class Deployment
{
    public function __construct(
        public int $id,
        public string $alias,
        public string $providerName,
        public string $model,
        public int $priority = 1,
        public int $weight = 100,
        public int|null $rpmLimit = null,
        public float $pricingInput = 0.0,
        public float $pricingOutput = 0.0,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            alias: (string) $row['alias'],
            providerName: (string) $row['provider_name'],
            model: (string) $row['model'],
            priority: (int) ($row['priority'] ?? 1),
            weight: (int) ($row['weight'] ?? 100),
            rpmLimit: isset($row['rpm_limit']) ? (int) $row['rpm_limit'] : null,
            pricingInput: (float) ($row['pricing_input'] ?? 0.0),
            pricingOutput: (float) ($row['pricing_output'] ?? 0.0),
        );
    }
}
