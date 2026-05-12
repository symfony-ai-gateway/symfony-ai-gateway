<?php

declare(strict_types=1);

namespace AIGateway\Standalone\Controller;

use AIGateway\Config\ModelRegistry;
use AIGateway\Core\GatewayInterface;
use AIGateway\Core\NormalizedRequest;
use AIGateway\Core\NormalizedStreamChunk;
use AIGateway\Logging\RequestLogger;
use AIGateway\Metrics\PrometheusMetrics;

use function count;

use const JSON_THROW_ON_ERROR;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ChatController
{
    public function __construct(
        private readonly GatewayInterface $gateway,
        private readonly ModelRegistry|null $modelRegistry = null,
        private readonly RequestLogger|null $requestLogger = null,
        private readonly PrometheusMetrics|null $metrics = null,
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
        $models = $this->modelRegistry?->getAvailableModels() ?? [];

        return new JsonResponse([
            'status' => 'ok',
            'providers_configured' => count($models) > 0 ? true : false,
            'models_available' => count($models),
        ]);
    }

    public function metrics(): Response
    {
        if (null === $this->metrics) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new Response(
            $this->metrics->render(),
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; version=0.0.4'],
        );
    }

    public function stats(): JsonResponse
    {
        if (null === $this->requestLogger) {
            return new JsonResponse(['error' => 'RequestLogger not configured'], 503);
        }

        return new JsonResponse([
            'total_requests' => $this->requestLogger->getTotalRequests(),
            'total_errors' => $this->requestLogger->getTotalErrors(),
            'avg_duration_ms' => round($this->requestLogger->getAverageDurationMs(), 2),
        ]);
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
