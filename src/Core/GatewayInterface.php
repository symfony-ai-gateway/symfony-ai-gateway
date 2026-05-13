<?php

declare(strict_types=1);

namespace AIGateway\Core;

use AIGateway\Auth\ApiKeyContext;
use Generator;

interface GatewayInterface
{
    public function chat(NormalizedRequest $request, ApiKeyContext|null $context = null): NormalizedResponse;

    /**
     * @return Generator<int, NormalizedStreamChunk>
     */
    public function chatStream(NormalizedRequest $request, ApiKeyContext|null $context = null): Generator;
}
