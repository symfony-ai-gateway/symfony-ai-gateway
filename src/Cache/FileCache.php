<?php

declare(strict_types=1);

namespace AIGateway\Cache;

use const DIRECTORY_SEPARATOR;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;

use const JSON_THROW_ON_ERROR;

use function mkdir;
use function rtrim;
use function sha1;
use function time;

final class FileCache implements CacheInterface
{
    private readonly string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o775, true);
        }
    }

    public function get(string $key): string|null
    {
        $path = $this->path($key);

        if (!file_exists($path)) {
            return null;
        }

        $data = file_get_contents($path);

        if (false === $data) {
            return null;
        }

        $entry = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

        if ($entry['expires_at'] < time()) {
            @unlink($path);

            return null;
        }

        return $entry['value'];
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $entry = [
            'value' => $value,
            'expires_at' => time() + $ttlSeconds,
        ];

        file_put_contents($this->path($key), json_encode($entry, JSON_THROW_ON_ERROR));
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    public function clear(): void
    {
        $files = glob($this->directory.DIRECTORY_SEPARATOR.'*.cache');

        if (false !== $files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    public function has(string $key): bool
    {
        return null !== $this->get($key);
    }

    private function path(string $key): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.sha1($key).'.cache';
    }
}
