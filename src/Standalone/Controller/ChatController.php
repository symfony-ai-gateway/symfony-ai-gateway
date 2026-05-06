<?php

declare(strict_types=1);

namespace AIGateway\Standalone\Controller;

use AIGateway\Core\GatewayInterface;
use AIGateway\Core\NormalizedRequest;
use AIGateway\Core\NormalizedStreamChunk;

use const JSON_THROW_ON_ERROR;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ChatController
{
    public function __construct(
        private readonly GatewayInterface $gateway,
    ) {
    }

    public function chat(Request $request): JsonResponse|StreamedResponse
    {
        $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $normalized = NormalizedRequest::fromArray($body);

        if ($normalized->stream) {
            return $this->streamResponse($normalized);
        }

        $response = $this->gateway->chat($normalized);

        return new JsonResponse($response->toArray(), $response->statusCode);
    }

    public function models(): JsonResponse
    {
        return new JsonResponse([
            'object' => 'list',
            'data' => [],
        ]);
    }

    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    private function streamResponse(NormalizedRequest $request): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($request): void {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');

            foreach ($this->gateway->chatStream($request) as $chunk) {
                $this->emitSSE($chunk);
            }

            $this->emitDone();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function emitSSE(NormalizedStreamChunk $chunk): void
    {
        echo 'data: '.json_encode($chunk->toArray(), JSON_THROW_ON_ERROR)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    private function emitDone(): void
    {
        echo "data: [DONE]\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
