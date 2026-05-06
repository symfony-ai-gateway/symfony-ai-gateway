<?php

declare(strict_types=1);

namespace AIGateway\Pipeline;

use AIGateway\Core\NormalizedRequest;
use AIGateway\Core\NormalizedResponse;
use AIGateway\Exception\GatewayException;
use AIGateway\Provider\ProviderAdapterInterface;
use AIGateway\Provider\ProviderRequest;
use AIGateway\Provider\ProviderResponse;

use function in_array;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

use function sprintf;

final class FallbackStrategy
{
    private LoggerInterface $logger;

    /**
     * @param list<string> $modelAliases   Ordered list of models to try
     * @param RetryConfig  $retryConfig    Retry configuration per model
     * @param list<int>    $failFastErrors HTTP status codes that should NOT trigger fallback
     */
    public function __construct(
        private readonly array $modelAliases,
        private readonly RetryConfig $retryConfig = new RetryConfig(),
        private readonly array $failFastErrors = [400, 401, 403],
        LoggerInterface|null $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Execute the fallback pipeline.
     *
     * @param callable(string $modelAlias): ProviderAdapterInterface $adapterResolver
     * @param callable(ProviderRequest $request): ProviderResponse   $httpCaller
     */
    public function execute(
        NormalizedRequest $request,
        callable $adapterResolver,
        callable $httpCaller,
    ): NormalizedResponse {
        $lastError = null;
        $attemptedModels = [];

        foreach ($this->modelAliases as $modelAlias) {
            $adapter = $adapterResolver($modelAlias);
            $providerRequest = $adapter->translateRequest(
                $this->withModel($request, $modelAlias),
            );

            for ($attempt = 0; $attempt <= $this->retryConfig->maxAttempts; ++$attempt) {
                $this->logger->info(sprintf(
                    '[AIGateway] Trying model=%s provider=%s attempt=%d/%d',
                    $modelAlias,
                    $adapter->getName(),
                    $attempt + 1,
                    $this->retryConfig->maxAttempts + 1,
                ));

                try {
                    $providerResponse = $httpCaller($providerRequest);

                    if ($providerResponse->statusCode >= 400) {
                        if (in_array($providerResponse->statusCode, $this->failFastErrors, true)) {
                            $error = $adapter->parseError($providerResponse->statusCode, $providerResponse->body);
                            $this->logger->error(sprintf(
                                '[AIGateway] Non-retryable error from %s: %s',
                                $adapter->getName(),
                                $error->message,
                            ));

                            throw GatewayException::providerError($adapter->getName(), $providerResponse->statusCode, $error->message);
                        }

                        if (!$adapter->isRetryableError($providerResponse->statusCode, $providerResponse->body)) {
                            break;
                        }

                        $lastError = $adapter->parseError($providerResponse->statusCode, $providerResponse->body);
                        $this->logger->warning(sprintf(
                            '[AIGateway] Retryable error from %s (HTTP %d), attempt %d/%d',
                            $adapter->getName(),
                            $providerResponse->statusCode,
                            $attempt + 1,
                            $this->retryConfig->maxAttempts + 1,
                        ));

                        if ($attempt < $this->retryConfig->maxAttempts) {
                            usleep($this->retryConfig->getDelayForAttempt($attempt) * 1000);
                        }

                        continue;
                    }

                    $response = $adapter->translateResponse($providerResponse, $request->model);

                    if ([] !== $attemptedModels) {
                        $this->logger->info(sprintf(
                            '[AIGateway] Fallback succeeded: %s (fallback from %s)',
                            $modelAlias,
                            implode(', ', $attemptedModels),
                        ));
                    }

                    return $response;
                } catch (GatewayException $e) {
                    if (in_array($e->getCode(), $this->failFastErrors, true)) {
                        throw $e;
                    }

                    $lastError = new RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
                }
            }

            $attemptedModels[] = $modelAlias;
        }

        throw GatewayException::allProvidersFailed($request->model);
    }

    private function withModel(NormalizedRequest $request, string $modelAlias): NormalizedRequest
    {
        return new NormalizedRequest(
            model: $modelAlias,
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
