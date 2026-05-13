<?php

declare(strict_types=1);

namespace AIGateway\Bundle\Routing;

use AIGateway\Controller\ChatController;
use AIGateway\Controller\DashboardController;
use LogicException;

use function sprintf;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

final class AIGatewayRouteLoader extends Loader
{
    private bool $loaded = false;

    public function __construct(
        private readonly string $prefix = '',
        private readonly bool $enabled = true,
    ) {
        parent::__construct();
    }

    public function load(mixed $resource, string|null $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new LogicException('AIGatewayRouteLoader has already been loaded.');
        }
        $this->loaded = true;

        if (!$this->enabled) {
            return new RouteCollection();
        }

        $collection = new RouteCollection();

        $imported = $this->importAttributes(ChatController::class);
        $collection->addCollection($imported);

        $imported = $this->importAttributes(DashboardController::class);
        $collection->addCollection($imported);

        if ('' !== $this->prefix) {
            $collection->addPrefix($this->prefix);
        }

        return $collection;
    }

    public function supports(mixed $resource, string|null $type = null): bool
    {
        return 'ai_gateway' === $type;
    }

    private function importAttributes(string $controllerClass): RouteCollection
    {
        $resolver = $this->resolver;
        if (null === $resolver) {
            throw new LogicException('A route resolver must be set before importing attributes.');
        }

        $loader = $resolver->resolve($controllerClass, 'attribute');
        if (false === $loader) {
            throw new LogicException(sprintf('Cannot resolve attribute loader for "%s".', $controllerClass));
        }

        return $loader->load($controllerClass, 'attribute');
    }
}
