<?php

declare(strict_types=1);

namespace AIGateway\Pipeline;

use function count;

use LogicException;

final class WeightedStrategy
{
    /** @var list<string> */
    private array $expandedPool;

    /**
     * @param array<string, int> $modelWeights model alias → weight (must be positive)
     */
    public function __construct(
        private readonly array $modelWeights,
    ) {
        $this->expandedPool = $this->buildExpandedPool();
    }

    public function next(): string
    {
        if ([] === $this->expandedPool) {
            throw new LogicException('No models configured for weighted selection.');
        }

        $index = random_int(0, count($this->expandedPool) - 1);

        return $this->expandedPool[$index];
    }

    /**
     * @return array<string, int>
     */
    public function getWeights(): array
    {
        return $this->modelWeights;
    }

    /**
     * @return list<string>
     */
    private function buildExpandedPool(): array
    {
        $pool = [];

        foreach ($this->modelWeights as $model => $weight) {
            $weight = max(1, (int) $weight);
            for ($i = 0; $i < $weight; ++$i) {
                $pool[] = $model;
            }
        }

        return $pool;
    }
}
