<?php

declare(strict_types=1);

use AIGateway\Standalone\StandaloneKernel;
use Symfony\HttpFoundation\Request;

require __DIR__.'/../vendor/autoload.php';

$kernel = new StandaloneKernel('prod', false);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
