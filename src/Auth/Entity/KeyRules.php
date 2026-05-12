<?php

declare(strict_types=1);

namespace AIGateway\Auth\Entity;

final readonly class KeyRules
{
    /**
     * @param list<string>|null $models null = tous autorisés, list = whitelist
     */
    public function __construct(
        public float|null $budgetPerDay = null,
        public float|null $budgetPerMonth = null,
        public int|null $rateLimitPerMinute = null,
        public array|null $models = null,
    ) {
    }

    public function mergeRestrictive(self $parent): self
    {
        return new self(
            budgetPerDay: $this->restrictBudget($this->budgetPerDay, $parent->budgetPerDay),
            budgetPerMonth: $this->restrictBudget($this->budgetPerMonth, $parent->budgetPerMonth),
            rateLimitPerMinute: $this->restrictRateLimit($this->rateLimitPerMinute, $parent->rateLimitPerMinute),
            models: $this->restrictModels($this->models, $parent->models),
        );
    }

    public function isModelAllowed(string $model): bool
    {
        if (null === $this->models) {
            return true;
        }

        foreach ($this->models as $allowed) {
            if (str_starts_with($model, $allowed) || $model === $allowed) {
                return true;
            }
        }

        return false;
    }

    private function restrictBudget(float|null $mine, float|null $parent): float|null
    {
        if (null === $parent) {
            return $mine;
        }

        if (null === $mine) {
            return $parent;
        }

        return min($mine, $parent);
    }

    private function restrictRateLimit(int|null $mine, int|null $parent): int|null
    {
        if (null === $parent) {
            return $mine;
        }

        if (null === $mine) {
            return $parent;
        }

        return min($mine, $parent);
    }

    /**
     * @param list<string>|null $mine
     * @param list<string>|null $parent
     *
     * @return list<string>|null
     */
    private function restrictModels(array|null $mine, array|null $parent): array|null
    {
        if (null === $parent) {
            return $mine;
        }

        if (null === $mine) {
            return $parent;
        }

        return array_values(array_intersect($mine, $parent));
    }
}
