<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$_SERVER['APP_RUNTIME'] = $_ENV['APP_RUNTIME'] ?? $_SERVER['APP_RUNTIME'] ?? 'Runtime\\FrankenPhpSymfony\\Runtime';

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';
