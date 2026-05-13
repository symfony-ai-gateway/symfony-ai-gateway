<?php

declare(strict_types=1);

namespace AIGateway\Provider;

use AIGateway\Core\NormalizedRequest;
use AIGateway\Core\NormalizedResponse;
use AIGateway\Core\NormalizedStreamChunk;
use Generator;

interface RuntimeProviderAdapterInterface extends ProviderAdapterInterface
{
    public function chat(NormalizedRequest $request, string $requestedModel): NormalizedResponse;

    /**
     * @return Generator<int, NormalizedStreamChunk>
     */
    public function chatStream(NormalizedRequest $request, string $requestedModel): Generator;
}
