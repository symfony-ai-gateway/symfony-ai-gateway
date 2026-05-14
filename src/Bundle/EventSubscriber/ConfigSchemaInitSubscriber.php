<?php

declare(strict_types=1);

namespace AIGateway\Bundle\EventSubscriber;

use AIGateway\Config\ConfigStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ConfigSchemaInitSubscriber implements EventSubscriberInterface
{
    private bool $initialized = false;

    public function __construct(
        private readonly ConfigStore|null $configStore = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->initialized || null === $this->configStore) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (!str_contains($path, '/dashboard')) {
            return;
        }

        $this->configStore->initializeSchema();
        $this->initialized = true;
    }
}
