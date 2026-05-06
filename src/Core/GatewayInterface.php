<?php

declare(strict_types=1);

namespace AIGateway\Core;

use Generator;

interface GatewayInterface
{
    public function chat(NormalizedRequest $request): NormalizedResponse;

    /**
     * @return Generator<int, NormalizedStreamChunk>
     */
    public function chatStream(NormalizedRequest $request): Generator;
}
