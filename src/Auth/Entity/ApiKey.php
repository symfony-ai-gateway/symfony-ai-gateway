<?php

declare(strict_types=1);

namespace AIGateway\Auth\Entity;

final readonly class ApiKey
{
    public function __construct(
        public string $id,
        public string $name,
        public string $keyHash,
        public string $tokenPrefix,
        public string|null $teamId = null,
        public KeyRules|null $overrides = null,
        public bool $enabled = true,
        public int|null $expiresAt = null,
        public int $createdAt = 0,
    ) {
    }

    public function isExpired(): bool
    {
        return null !== $this->expiresAt && $this->expiresAt < time();
    }

    public function resolveRules(Team $team): KeyRules
    {
        $teamRules = $team->rules;

        if (null === $this->overrides) {
            return $teamRules;
        }

        return $this->overrides->mergeRestrictive($teamRules);
    }
}
