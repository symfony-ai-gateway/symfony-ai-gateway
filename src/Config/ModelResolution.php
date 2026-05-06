<?php

declare(strict_types=1);

namespace PhiGateway\Config;

use PhiGateway\Provider\ProviderCapabilities;

/**
 * Resolved model configuration: links an alias to a provider + model name + pricing.
 */
final readonly class ModelResolution
{
    public function __construct(
        public string $alias,
        public string $provider,
        public string $model,
        public ModelPricing $pricing,
        public int $maxTokens = 128000,
        public ?ProviderCapabilities $capabilities = null,
    ) {
    }
}
