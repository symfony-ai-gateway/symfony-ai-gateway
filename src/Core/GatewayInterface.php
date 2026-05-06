<?php

declare(strict_types=1);

namespace AIGateway\Core;

interface GatewayInterface
{
    public function chat(NormalizedRequest $request): NormalizedResponse;
}
