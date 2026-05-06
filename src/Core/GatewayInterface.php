<?php

declare(strict_types=1);

namespace PhiGateway\Core;

interface GatewayInterface
{
    public function chat(NormalizedRequest $request): NormalizedResponse;
}
