<?php

declare(strict_types=1);

namespace AIGateway\Metrics;

use AIGateway\Logging\RequestLog;

use function sprintf;

final class PrometheusMetrics
{
    private int $totalRequests = 0;
    private int $totalErrors = 0;
    private float $totalCost = 0.0;
    private int $totalTokens = 0;
    private int $cacheHits = 0;

    /** @var array<string, int> */
    private array $requestsByProvider = [];

    /** @var array<string, int> */
    private array $requestsByModel = [];

    public function record(RequestLog $log): void
    {
        ++$this->totalRequests;
        $this->totalCost += $log->costUsd;
        $this->totalTokens += $log->totalTokens;

        if ($log->cached) {
            ++$this->cacheHits;
        }

        if (null !== $log->error || $log->statusCode >= 400) {
            ++$this->totalErrors;
        }

        if (!isset($this->requestsByProvider[$log->provider])) {
            $this->requestsByProvider[$log->provider] = 0;
        }

        ++$this->requestsByProvider[$log->provider];

        if (!isset($this->requestsByModel[$log->model])) {
            $this->requestsByModel[$log->model] = 0;
        }

        ++$this->requestsByModel[$log->model];
    }

    public function render(): string
    {
        $lines = [];

        $lines[] = '# HELP ai_gateway_requests_total Total number of requests';
        $lines[] = '# TYPE ai_gateway_requests_total counter';
        $lines[] = sprintf('ai_gateway_requests_total %d', $this->totalRequests);

        $lines[] = '# HELP ai_gateway_errors_total Total number of errors';
        $lines[] = '# TYPE ai_gateway_errors_total counter';
        $lines[] = sprintf('ai_gateway_errors_total %d', $this->totalErrors);

        $lines[] = '# HELP ai_gateway_cost_dollars_total Total cost in USD';
        $lines[] = '# TYPE ai_gateway_cost_dollars_total counter';
        $lines[] = sprintf('ai_gateway_cost_dollars_total %.6f', $this->totalCost);

        $lines[] = '# HELP ai_gateway_tokens_total Total tokens used';
        $lines[] = '# TYPE ai_gateway_tokens_total counter';
        $lines[] = sprintf('ai_gateway_tokens_total %d', $this->totalTokens);

        $lines[] = '# HELP ai_gateway_cache_hits_total Total cache hits';
        $lines[] = '# TYPE ai_gateway_cache_hits_total counter';
        $lines[] = sprintf('ai_gateway_cache_hits_total %d', $this->cacheHits);

        foreach ($this->requestsByProvider as $provider => $count) {
            $lines[] = sprintf('ai_gateway_requests_by_provider{provider="%s"} %d', $provider, $count);
        }

        foreach ($this->requestsByModel as $model => $count) {
            $lines[] = sprintf('ai_gateway_requests_by_model{model="%s"} %d', $model, $count);
        }

        return implode("\n", $lines)."\n";
    }
}
