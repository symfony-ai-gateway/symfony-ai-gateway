<?php

declare(strict_types=1);

namespace AIGateway\Auth\Store;

final readonly class KeyUsage
{
    public function __construct(
        public int $requests = 0,
        public int $tokens = 0,
        public float $costUsd = 0.0,
    ) {
    }
}
