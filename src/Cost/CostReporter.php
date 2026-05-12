<?php

declare(strict_types=1);

namespace AIGateway\Cost;

use AIGateway\Logging\RequestLog;

final class CostReporter
{
    /**
     * @param list<RequestLog> $logs
     *
     * @return list<array{provider: string, requests: int, tokens: int, cost: float}>
     */
    public static function byProvider(array $logs): array
    {
        $result = [];

        foreach ($logs as $log) {
            $key = $log->provider;

            if (!isset($result[$key])) {
                $result[$key] = ['provider' => $key, 'requests' => 0, 'tokens' => 0, 'cost' => 0.0];
            }

            ++$result[$key]['requests'];
            $result[$key]['tokens'] += $log->totalTokens;
            $result[$key]['cost'] += $log->costUsd;
        }

        return array_values($result);
    }

    /**
     * @param list<RequestLog> $logs
     *
     * @return list<array{model: string, requests: int, tokens: int, cost: float, avg_ms: float}>
     */
    public static function byModel(array $logs): array
    {
        $result = [];

        foreach ($logs as $log) {
            $key = $log->model;

            if (!isset($result[$key])) {
                $result[$key] = ['model' => $key, 'requests' => 0, 'tokens' => 0, 'cost' => 0.0, 'avg_ms' => 0.0, 'total_ms' => 0.0];
            }

            ++$result[$key]['requests'];
            $result[$key]['tokens'] += $log->totalTokens;
            $result[$key]['cost'] += $log->costUsd;
            $result[$key]['total_ms'] += $log->durationMs;
        }

        foreach ($result as $key => $row) {
            $result[$key]['avg_ms'] = $result[$key]['total_ms'] / $row['requests'];
            unset($result[$key]['total_ms']);
        }

        return array_values($result);
    }
}
