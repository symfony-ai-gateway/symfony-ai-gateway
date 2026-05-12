<?php

declare(strict_types=1);

namespace AIGateway\Cache;

interface CacheInterface
{
    public function get(string $key): string|null;

    public function set(string $key, string $value, int $ttlSeconds): void;

    public function delete(string $key): void;

    public function clear(): void;

    public function has(string $key): bool;
}
