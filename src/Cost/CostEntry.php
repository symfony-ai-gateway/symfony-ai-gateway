<?php

declare(strict_types=1);

namespace AIGateway\Cost;

final readonly class CostEntry
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $modelAlias,
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public float $costUsd,
        public bool $cached,
    ) {
    }
}
