<?php

declare(strict_types=1);

namespace PhiGateway\Bundle;

use function dirname;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class PhiGatewayBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
