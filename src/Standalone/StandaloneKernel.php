<?php

declare(strict_types=1);

namespace AIGateway\Standalone;

use AIGateway\Bundle\AIGatewayBundle;
use AIGateway\Standalone\EventListener\ExceptionListener;
use AIGateway\Standalone\EventListener\SecurityHeadersListener;

use function dirname;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

use function file_exists;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Yaml\Yaml;

final class StandaloneKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new AIGatewayBundle(),
        ];
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'AIGatewayStandaloneSecret',
            'router' => [
                'utf8' => true,
            ],
            'http_method_override' => false,
            'handle_all_throwables' => true,
        ]);

        $container->extension('twig', [
            'default_path' => '%kernel.project_dir%/templates',
        ]);

        $configFile = $this->getProjectDir().'/config/packages/ai_gateway.yaml';
        if (file_exists($configFile)) {
            $parsed = Yaml::parseFile($configFile);
            $container->extension('ai_gateway', $parsed['ai_gateway'] ?? []);
        }

        $container->services()
            ->set(ExceptionListener::class)
            ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException'])
            ->set(SecurityHeadersListener::class)
            ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse']);

        $container->services()
            ->load('AIGateway\\Standalone\\Controller\\', $this->getProjectDir().'/src/Standalone/Controller/')
            ->autowire()
            ->autoconfigure();

        $container->services()
            ->set('doctrine.dbal.default_connection', Connection::class)
            ->factory([DriverManager::class, 'getConnection'])
            ->args([['driver' => 'pdo_sqlite', 'path' => $this->getProjectDir().'/data/auth.db']]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('Controller/', 'attribute');
    }
}
