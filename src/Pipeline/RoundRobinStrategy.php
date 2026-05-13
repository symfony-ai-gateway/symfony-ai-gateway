<?php

declare(strict_types=1);

namespace AIGateway\Pipeline;

use function count;

use LogicException;

final class RoundRobinStrategy
{
    private int $currentIndex = 0;

    /**
     * @param list<string> $modelAliases Models to cycle through
     */
    public function __construct(
        private readonly array $modelAliases,
    ) {
    }

    public function next(): string
    {
        if ([] === $this->modelAliases) {
            throw new LogicException('No models configured for round-robin.');
        }

        $model = $this->modelAliases[$this->currentIndex % count($this->modelAliases)];
        ++$this->currentIndex;

        return $model;
    }

    public function reset(): void
    {
        $this->currentIndex = 0;
    }
}
