<?php

declare(strict_types=1);

namespace AIGateway\Cache;

use AIGateway\Core\NormalizedRequest;
use AIGateway\Core\NormalizedResponse;

use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class CacheManager
{
    private int $defaultTtl;

    public function __construct(
        private readonly CacheInterface $cache,
        int $defaultTtl = 1800,
        private readonly bool $enabled = true,
    ) {
        $this->defaultTtl = $defaultTtl;
    }

    public function lookup(NormalizedRequest $request): NormalizedResponse|null
    {
        if (!$this->enabled || $request->stream) {
            return null;
        }

        $key = $this->generateKey($request);
        $cached = $this->cache->get($key);

        if (null === $cached) {
            return null;
        }

        $data = json_decode($cached, true, 512, JSON_THROW_ON_ERROR);

        return NormalizedResponse::fromArray($data);
    }

    public function store(NormalizedRequest $request, NormalizedResponse $response, int|null $ttl = null): void
    {
        if (!$this->enabled || $request->stream) {
            return;
        }

        $key = $this->generateKey($request);
        $this->cache->set($key, json_encode($response->toArray(), JSON_THROW_ON_ERROR), $ttl ?? $this->defaultTtl);
    }

    public function generateKey(NormalizedRequest $request): string
    {
        $payload = [
            'model' => $request->model,
            'messages' => array_map(static fn ($m) => $m->toArray(), $request->messages),
            'temperature' => $request->temperature,
            'top_p' => $request->topP,
            'max_tokens' => $request->maxTokens,
            'frequency_penalty' => $request->frequencyPenalty,
            'presence_penalty' => $request->presencePenalty,
            'stop' => $request->stop,
            'seed' => $request->seed,
        ];

        return 'ai_gateway:'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function invalidate(NormalizedRequest $request): void
    {
        $this->cache->delete($this->generateKey($request));
    }
}
