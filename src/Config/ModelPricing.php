<?php

declare(strict_types=1);

namespace PhiGateway\Config;

/**
 * Pricing information for a specific model.
 */
final readonly class ModelPricing
{
    public function __construct(
        public float $inputPerMillion = 0.0,
        public float $outputPerMillion = 0.0,
    ) {
    }

    public function calculateCost(int $promptTokens, int $completionTokens): float
    {
        $inputCost = ($promptTokens / 1_000_000) * $this->inputPerMillion;
        $outputCost = ($completionTokens / 1_000_000) * $this->outputPerMillion;

        return $inputCost + $outputCost;
    }
}
