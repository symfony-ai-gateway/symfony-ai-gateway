<?php

declare(strict_types=1);

namespace AIGateway\Core;

use AIGateway\Cache\CacheManager;
use AIGateway\Config\ModelRegistry;
use AIGateway\Config\ModelResolution;
use AIGateway\Cost\CostTracker;
use AIGateway\Exception\GatewayException;
use AIGateway\Pipeline\FallbackStrategy;
use AIGateway\Pipeline\RetryConfig;
use AIGateway\Provider\ProviderAdapterInterface;
use AIGateway\Provider\ProviderRequest;
use AIGateway\Provider\ProviderResponse;
use AIGateway\Provider\RuntimeProviderAdapterInterface;
use AIGateway\Provider\StreamingProviderAdapterInterface;
use AIGateway\RateLimit\MultiLevelRateLimiter;
use Generator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;

final class Gateway implements GatewayInterface
{
    private LoggerInterface $logger;

    /**
     * @param array<string, ProviderAdapterInterface> $providers provider name → adapter
     * @param array<string, list<string>>             $pipelines pipeline name → ordered model aliases
     * @param array<string, string>                   $aliases   routing alias → model alias or "pipeline:name"
     */
    public function __construct(
        private readonly ModelRegistry $modelRegistry,
        private readonly ProviderHttpClient $httpClient,
        private readonly StreamProxy $streamProxy,
        private readonly array $providers = [],
        private readonly array $pipelines = [],
        private readonly array $aliases = [],
        private readonly RetryConfig $defaultRetryConfig = new RetryConfig(),
        private readonly CacheManager|null $cacheManager = null,
        private readonly MultiLevelRateLimiter|null $rateLimiter = null,
        private readonly CostTracker|null $costTracker = null,
        LoggerInterface|null $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function chat(NormalizedRequest $request): NormalizedResponse
    {
        $startTime = microtime(true);

        $this->rateLimiter?->check(['global' => 'global', 'model' => $request->model]);

        $cached = $this->cacheManager?->lookup($request);
        if (null !== $cached) {
            $this->rateLimiter?->increment(['global' => 'global', 'model' => $request->model]);

            return new NormalizedResponse(
                id: $cached->id,
                model: $cached->model,
                provider: $cached->provider,
                choices: $cached->choices,
                usage: $cached->usage,
                statusCode: $cached->statusCode,
                systemFingerprint: $cached->systemFingerprint,
                cacheHit: true,
                costUsd: 0.0,
            );
        }

        $requestedModel = $request->model;

        $resolvedTarget = $this->aliases[$requestedModel]
            ?? $requestedModel;

        $resolution = null;

        if (str_starts_with($resolvedTarget, 'pipeline:')) {
            $pipelineName = substr($resolvedTarget, 9);
            $response = $this->executePipeline($request, $pipelineName);
        } elseif ($this->modelRegistry->has($requestedModel)) {
            $resolution = $this->modelRegistry->resolve($requestedModel);
            $response = $this->executeSingle($request, $resolution);
        } else {
            throw GatewayException::modelNotFound($requestedModel, $this->modelRegistry->getAvailableModels());
        }

        $durationMs = (microtime(true) - $startTime) * 1000;

        $cost = ($resolution ?? $this->modelRegistry->resolve($response->model))
            ->pricing->calculateCost(
                $response->usage->promptTokens,
                $response->usage->completionTokens,
            );

        $finalResponse = new NormalizedResponse(
            id: $response->id,
            model: $response->model,
            provider: $response->provider,
            choices: $response->choices,
            usage: $response->usage,
            statusCode: $response->statusCode,
            systemFingerprint: $response->systemFingerprint,
            fallbackFrom: $response->fallbackFrom,
            durationMs: $durationMs,
            cacheHit: false,
            costUsd: $cost,
        );

        $this->cacheManager?->store($request, $finalResponse);
        $this->rateLimiter?->increment(['global' => 'global', 'model' => $request->model]);
        $this->costTracker?->record($finalResponse, $requestedModel);

        return $finalResponse;
    }

    public function chatStream(NormalizedRequest $request): Generator
    {
        $streamRequest = new NormalizedRequest(
            model: $request->model,
            messages: $request->messages,
            temperature: $request->temperature,
            maxTokens: $request->maxTokens,
            topP: $request->topP,
            frequencyPenalty: $request->frequencyPenalty,
            presencePenalty: $request->presencePenalty,
            stop: $request->stop,
            stream: true,
            tools: $request->tools,
            toolChoice: $request->toolChoice,
            responseFormat: $request->responseFormat,
            seed: $request->seed,
            user: $request->user,
        );

        if (!$this->modelRegistry->has($streamRequest->model)) {
            throw GatewayException::modelNotFound($streamRequest->model, $this->modelRegistry->getAvailableModels());
        }

        $resolution = $this->modelRegistry->resolve($streamRequest->model);
        $adapter = $this->getProvider($resolution->provider);

        if ($adapter instanceof RuntimeProviderAdapterInterface) {
            yield from $adapter->chatStream($this->withModel($streamRequest, $resolution->model), $streamRequest->model);

            return;
        }

        if (!$adapter instanceof StreamingProviderAdapterInterface) {
            throw GatewayException::invalidRequest(sprintf('Provider "%s" does not support streaming.', $resolution->provider));
        }

        $providerRequest = $adapter->translateRequest($this->withModel($streamRequest, $resolution->model));

        yield from $this->streamProxy->proxy($providerRequest, $adapter, $streamRequest->model);
    }

    private function executeSingle(NormalizedRequest $request, ModelResolution $resolution): NormalizedResponse
    {
        $adapter = $this->getProvider($resolution->provider);

        if ($adapter instanceof RuntimeProviderAdapterInterface) {
            return $adapter->chat($this->withModel($request, $resolution->model), $request->model);
        }

        $providerRequest = $adapter->translateRequest($this->withModel($request, $resolution->model));
        $providerResponse = $this->httpClient->send($providerRequest);

        if ($providerResponse->statusCode >= 400) {
            $error = $adapter->parseError($providerResponse->statusCode, $providerResponse->body);

            throw GatewayException::providerError($adapter->getName(), $providerResponse->statusCode, $error->message);
        }

        return $adapter->translateResponse($providerResponse, $request->model);
    }

    private function executePipeline(NormalizedRequest $request, string $pipelineName): NormalizedResponse
    {
        $models = $this->pipelines[$pipelineName]
            ?? throw GatewayException::invalidRequest(sprintf('Pipeline "%s" not found.', $pipelineName));

        $strategy = new FallbackStrategy(
            modelAliases: $models,
            retryConfig: $this->defaultRetryConfig,
            logger: $this->logger,
        );

        $adapterResolver = fn (string $modelAlias): ProviderAdapterInterface => $this->resolveAdapter($modelAlias);
        $httpCaller = fn (ProviderRequest $req): ProviderResponse => $this->httpClient->send($req);

        return $strategy->execute($request, $adapterResolver, $httpCaller);
    }

    private function resolveAdapter(string $modelAlias): ProviderAdapterInterface
    {
        $resolution = $this->modelRegistry->resolve($modelAlias);

        return $this->getProvider($resolution->provider);
    }

    private function getProvider(string $providerName): ProviderAdapterInterface
    {
        if (!isset($this->providers[$providerName])) {
            throw GatewayException::providerNotFound($providerName);
        }

        return $this->providers[$providerName];
    }

    private function withModel(NormalizedRequest $request, string $model): NormalizedRequest
    {
        return new NormalizedRequest(
            model: $model,
            messages: $request->messages,
            temperature: $request->temperature,
            maxTokens: $request->maxTokens,
            topP: $request->topP,
            frequencyPenalty: $request->frequencyPenalty,
            presencePenalty: $request->presencePenalty,
            stop: $request->stop,
            stream: $request->stream,
            tools: $request->tools,
            toolChoice: $request->toolChoice,
            responseFormat: $request->responseFormat,
            seed: $request->seed,
            user: $request->user,
        );
    }
}
