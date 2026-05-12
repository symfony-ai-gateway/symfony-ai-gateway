<?php

declare(strict_types=1);

namespace AIGateway\Logging;

final readonly class RequestLog
{
    public function __construct(
        public string $id,
        public string $model,
        public string $provider,
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public float $costUsd,
        public float $durationMs,
        public bool $cached,
        public int $statusCode,
        public string|null $error = null,
    ) {
    }
}
