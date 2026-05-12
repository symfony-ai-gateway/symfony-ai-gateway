<?php

declare(strict_types=1);

namespace AIGateway\Standalone\EventListener;

use AIGateway\Exception\GatewayException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

final class ExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof GatewayException) {
            $event->setResponse(new JsonResponse([
                'error' => [
                    'message' => $exception->getMessage(),
                    'type' => 'gateway_error',
                    'code' => $exception->getCode(),
                ],
            ], $exception->getCode() >= 400 ? $exception->getCode() : 500));

            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => [
                'message' => 'Internal server error.',
                'type' => 'internal_error',
                'code' => 500,
            ],
        ], Response::HTTP_INTERNAL_SERVER_ERROR));
    }
}
