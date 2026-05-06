<?php

declare(strict_types=1);

namespace AIGateway\Bundle;

use function dirname;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class AIGatewayBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
