<?php

declare(strict_types=1);

namespace AIGateway\Router;

use AIGateway\Exception\GatewayException;

final class DeploymentRouter
{
    private int $roundRobinIndex = 0;

    /** @var array<string, float> provider_name → avg latency ms */
    private array $latencyScores = [];

    /** @var array<string, int> provider_name → active requests count */
    private array $activeRequests = [];

    /** @var array<string, int> provider_name → cooldown until timestamp */
    private array $cooldowns = [];

    /**
     * @param list<Deployment> $deployments
     */
    public function pick(array $deployments, string $strategy = 'weighted'): Deployment
    {
        $available = $this->filterAvailable($deployments);

        if ([] === $available) {
            $available = $this->filterAvailable($deployments, allowFallback: true);
        }

        if ([] === $available) {
            throw GatewayException::providerError('all-deployments', 503, 'All deployments for this model are in cooldown or disabled.');
        }

        $bestPriority = min(array_map(static fn (Deployment $d): int => $d->priority, $available));
        $primary = array_values(array_filter($available, static fn (Deployment $d): bool => $d->priority === $bestPriority));

        return match ($strategy) {
            'round-robin' => $this->pickRoundRobin($primary),
            'weighted' => $this->pickWeighted($primary),
            'least-busy' => $this->pickLeastBusy($primary),
            'cost-based' => $this->pickCostBased($primary),
            'latency-based' => $this->pickLatencyBased($primary),
            default => $this->pickWeighted($primary),
        };
    }

    public function recordLatency(string $providerName, float $ms): void
    {
        $current = $this->latencyScores[$providerName] ?? $ms;
        $this->latencyScores[$providerName] = ($current * 0.7) + ($ms * 0.3);
    }

    public function cooldown(string $providerName, int $seconds = 60): void
    {
        $this->cooldowns[$providerName] = time() + $seconds;
    }

    public function incrementActive(string $providerName): void
    {
        $this->activeRequests[$providerName] = ($this->activeRequests[$providerName] ?? 0) + 1;
    }

    public function decrementActive(string $providerName): void
    {
        if (isset($this->activeRequests[$providerName])) {
            $this->activeRequests[$providerName]--;
            if ($this->activeRequests[$providerName] <= 0) {
                unset($this->activeRequests[$providerName]);
            }
        }
    }

    /**
     * @param list<Deployment> $deployments
     * @return list<Deployment>
     */
    private function filterAvailable(array $deployments, bool $allowFallback = false): array
    {
        $now = time();

        return array_values(array_filter($deployments, function (Deployment $d) use ($now, $allowFallback): bool {
            $cooled = !isset($this->cooldowns[$d->providerName]) || $this->cooldowns[$d->providerName] <= $now;

            if ($cooled) {
                unset($this->cooldowns[$d->providerName]);
            }

            if ($allowFallback) {
                return true;
            }

            return $cooled;
        }));
    }

    /**
     * @param non-empty-list<Deployment> $deployments
     */
    private function pickRoundRobin(array $deployments): Deployment
    {
        $idx = $this->roundRobinIndex % count($deployments);
        $this->roundRobinIndex++;

        return $deployments[$idx];
    }

    /**
     * @param non-empty-list<Deployment> $deployments
     */
    private function pickWeighted(array $deployments): Deployment
    {
        $totalWeight = (int) array_sum(array_map(static fn (Deployment $d): int => $d->weight, $deployments));

        if ($totalWeight <= 0) {
            return $deployments[0];
        }

        $rand = random_int(1, $totalWeight);
        $cumulative = 0;

        foreach ($deployments as $deployment) {
            $cumulative += $deployment->weight;
            if ($rand <= $cumulative) {
                return $deployment;
            }
        }

        return $deployments[0];
    }

    /**
     * @param non-empty-list<Deployment> $deployments
     */
    private function pickLeastBusy(array $deployments): Deployment
    {
        usort($deployments, fn (Deployment $a, Deployment $b): int =>
            ($this->activeRequests[$a->providerName] ?? 0) <=> ($this->activeRequests[$b->providerName] ?? 0));

        return $deployments[0];
    }

    /**
     * @param non-empty-list<Deployment> $deployments
     */
    private function pickCostBased(array $deployments): Deployment
    {
        usort($deployments, static fn (Deployment $a, Deployment $b): int =>
            ((int) ($a->pricingInput + $a->pricingOutput)) <=> ((int) ($b->pricingInput + $b->pricingOutput)));

        return $deployments[0];
    }

    /**
     * @param non-empty-list<Deployment> $deployments
     */
    private function pickLatencyBased(array $deployments): Deployment
    {
        usort($deployments, fn (Deployment $a, Deployment $b): int =>
            (int) (($this->latencyScores[$a->providerName] ?? 9999)) <=> (int) (($this->latencyScores[$b->providerName] ?? 9999)));

        return $deployments[0];
    }
}
