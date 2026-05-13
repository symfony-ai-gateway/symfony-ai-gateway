<?php

declare(strict_types=1);

namespace AIGateway\Cost;

use AIGateway\Config\ModelRegistry;
use AIGateway\Core\NormalizedResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;

final class CostTracker
{
    private LoggerInterface $logger;

    /** @var list<CostEntry> */
    private array $entries = [];

    public function __construct(
        private readonly ModelRegistry $modelRegistry,
        LoggerInterface|null $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function record(NormalizedResponse $response, string $modelAlias): CostEntry
    {
        $pricing = $this->modelRegistry->has($modelAlias)
            ? $this->modelRegistry->resolve($modelAlias)->pricing
            : null;

        $costUsd = $pricing?->calculateCost(
            $response->usage->promptTokens,
            $response->usage->completionTokens,
        ) ?? 0.0;

        $entry = new CostEntry(
            provider: $response->provider,
            model: $response->model,
            modelAlias: $modelAlias,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            totalTokens: $response->usage->totalTokens,
            costUsd: $costUsd,
            cached: $response->cacheHit,
        );

        $this->entries[] = $entry;

        $this->logger->debug(sprintf(
            '[AIGateway] Cost: $%.6f (%d+%d tokens, %s/%s%s)',
            $costUsd,
            $entry->promptTokens,
            $entry->completionTokens,
            $entry->provider,
            $entry->model,
            $entry->cached ? ', cached' : '',
        ));

        return $entry;
    }

    /**
     * @return list<CostEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function getTotalCost(): float
    {
        $total = 0.0;

        foreach ($this->entries as $entry) {
            $total += $entry->costUsd;
        }

        return $total;
    }

    public function getTotalTokens(): int
    {
        $total = 0;

        foreach ($this->entries as $entry) {
            $total += $entry->totalTokens;
        }

        return $total;
    }
}
