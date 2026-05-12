<?php

declare(strict_types=1);

namespace AIGateway\Auth;

use AIGateway\Auth\Entity\ApiKey;
use AIGateway\Auth\Entity\KeyRules;

final readonly class ApiKeyContext
{
    public function __construct(
        public ApiKey $apiKey,
        public KeyRules $resolvedRules,
    ) {
    }
}
