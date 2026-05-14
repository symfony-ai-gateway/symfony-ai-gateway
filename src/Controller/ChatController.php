<?php

declare(strict_types=1);

namespace AIGateway\Controller;

use AIGateway\Auth\ApiKeyAuthenticator;
use AIGateway\Auth\ApiKeyContext;
use AIGateway\Config\ConfigStore;
use AIGateway\Config\ModelRegistry;
use AIGateway\Core\GatewayInterface;
use AIGateway\Core\NormalizedRequest;
use AIGateway\Core\NormalizedStreamChunk;
use AIGateway\Exception\GatewayException;
use AIGateway\Logging\RequestLogger;
use AIGateway\Metrics\PrometheusMetrics;

use function array_unique;
use function count;

use const JSON_THROW_ON_ERROR;

use function preg_replace;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

use function trim;

final class ChatController
{
    public function __construct(
        private readonly GatewayInterface $gateway,
        private readonly ModelRegistry|null $modelRegistry = null,
        private readonly ConfigStore|null $configStore = null,
        private readonly RequestLogger|null $requestLogger = null,
        private readonly PrometheusMetrics|null $metrics = null,
        private readonly ApiKeyAuthenticator|null $authenticator = null,
        private readonly bool $authRequired = true,
    ) {
    }

    #[Route('/v1/chat/completions', name: 'ai_gateway_chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse|StreamedResponse
    {
        $context = $this->authenticate($request);

        $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $normalized = NormalizedRequest::fromArray($body);

        if ($normalized->stream) {
            return $this->streamResponse($normalized, $context);
        }

        $response = $this->gateway->chat($normalized, $context);

        return new JsonResponse($response->toArray(), $response->statusCode);
    }

    #[Route('/v1/models', name: 'ai_gateway_models', methods: ['GET'])]
    public function models(): JsonResponse
    {
        $yamlModels = $this->modelRegistry?->getAvailableModels() ?? [];
        $dbModels = $this->configStore?->listModels() ?? [];

        $dbAliases = array_map(static fn (array $m): string => $m['alias'], $dbModels);
        $allAliases = array_unique([...$yamlModels, ...$dbAliases]);
        sort($allAliases);

        $data = [];
        foreach ($allAliases as $alias) {
            $data[] = [
                'id' => $alias,
                'object' => 'model',
                'owned_by' => 'aigateway',
            ];
        }

        return new JsonResponse([
            'object' => 'list',
            'data' => $data,
        ]);
    }

    #[Route('/v1/health', name: 'ai_gateway_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $models = $this->modelRegistry?->getAvailableModels() ?? [];

        return new JsonResponse([
            'status' => 'ok',
            'providers_configured' => count($models) > 0,
            'models_available' => count($models),
        ]);
    }

    #[Route('/v1/metrics', name: 'ai_gateway_metrics', methods: ['GET'])]
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

    #[Route('/v1/stats', name: 'ai_gateway_stats', methods: ['GET'])]
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

    private function authenticate(Request $request): ApiKeyContext|null
    {
        if (null === $this->authenticator) {
            return null;
        }

        $authorization = (string) $request->headers->get('Authorization', '');

        if ('' === $authorization) {
            if ($this->authRequired) {
                throw GatewayException::authenticationFailed('Missing Authorization header.');
            }

            return null;
        }

        $token = trim((string) preg_replace('/^Bearer\s+/i', '', $authorization));

        if ('' === $token) {
            if ($this->authRequired) {
                throw GatewayException::authenticationFailed('Invalid Authorization header format.');
            }

            return null;
        }

        return $this->authenticator->authenticate($token);
    }

    private function streamResponse(NormalizedRequest $request, ApiKeyContext|null $context): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($request, $context): void {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');

            foreach ($this->gateway->chatStream($request, $context) as $chunk) {
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
