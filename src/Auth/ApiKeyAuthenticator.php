<?php

declare(strict_types=1);

namespace AIGateway\Auth;

use AIGateway\Auth\Entity\ApiKey;
use AIGateway\Auth\Entity\KeyRules;
use AIGateway\Auth\Entity\Team;
use AIGateway\Auth\Store\KeyStoreInterface;
use AIGateway\Exception\GatewayException;

use function count;
use function hash;
use function sprintf;
use function time;

final class ApiKeyAuthenticator
{
    public function __construct(
        private readonly KeyStoreInterface $keyStore,
    ) {
    }

    public function authenticate(string $token): ApiKeyContext
    {
        $keyHash = hash('sha256', $token);

        $apiKey = $this->keyStore->findKeyByHash($keyHash);

        if (null === $apiKey) {
            throw GatewayException::authenticationFailed('Invalid API key.');
        }

        if (!$apiKey->enabled) {
            throw GatewayException::authenticationFailed(sprintf('API key "%s" is disabled.', $apiKey->name));
        }

        if ($apiKey->isExpired()) {
            throw GatewayException::authenticationFailed(sprintf('API key "%s" has expired.', $apiKey->name));
        }

        $resolvedRules = $this->resolveRules($apiKey);

        return new ApiKeyContext($apiKey, $resolvedRules);
    }

    private function resolveRules(ApiKey $apiKey): KeyRules
    {
        if (null === $apiKey->teamId) {
            return $apiKey->overrides ?? new KeyRules();
        }

        $ancestry = $this->keyStore->findTeamAncestry($apiKey->teamId);

        if ([] === $ancestry) {
            return $apiKey->overrides ?? new KeyRules();
        }

        $mergedRules = $ancestry[0]->rules;

        for ($i = 1, $count = count($ancestry); $i < $count; ++$i) {
            $mergedRules = $ancestry[$i]->rules->mergeRestrictive($mergedRules);
        }

        return $apiKey->resolveRules(new Team(
            id: '__merged__',
            name: '__merged__',
            rules: $mergedRules,
            createdAt: time(),
        ));
    }
}
