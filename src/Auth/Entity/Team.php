<?php

declare(strict_types=1);

namespace AIGateway\Auth\Entity;

final readonly class Team
{
    public function __construct(
        public string $id,
        public string $name,
        public string|null $parentId = null,
        public KeyRules $rules = new KeyRules(),
        public int $createdAt = 0,
    ) {
    }

    public function isRoot(): bool
    {
        return null === $this->parentId;
    }
}
