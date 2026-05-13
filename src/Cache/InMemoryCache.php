<?php

declare(strict_types=1);

namespace AIGateway\Cache;

final class InMemoryCache implements CacheInterface
{
    /** @var array<string, array{value: string, expires_at: int}> */
    private array $store = [];

    public function get(string $key): string|null
    {
        if (!isset($this->store[$key])) {
            return null;
        }

        if ($this->store[$key]['expires_at'] < time()) {
            unset($this->store[$key]);

            return null;
        }

        return $this->store[$key]['value'];
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->store[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttlSeconds,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }

    public function clear(): void
    {
        $this->store = [];
    }

    public function has(string $key): bool
    {
        return null !== $this->get($key);
    }
}
