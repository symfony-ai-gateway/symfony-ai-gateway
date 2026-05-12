<?php

declare(strict_types=1);

namespace AIGateway\Tests\Auth\Entity;

use AIGateway\Auth\Entity\ApiKey;
use AIGateway\Auth\Entity\KeyRules;
use AIGateway\Auth\Entity\Team;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    public function testResolveRulesWithoutOverrides(): void
    {
        $team = new Team(
            id: 'team-1',
            name: 'Engineering',
            rules: new KeyRules(
                budgetPerDay: 100.0,
                budgetPerMonth: 2000.0,
                rateLimitPerMinute: 60,
                models: ['gpt-4o', 'claude-sonnet'],
            ),
        );

        $key = new ApiKey(
            id: 'key-1',
            name: 'Test Key',
            keyHash: 'hash',
            tokenPrefix: 'aig_1234',
            teamId: 'team-1',
        );

        $resolved = $key->resolveRules($team);

        $this->assertSame(100.0, $resolved->budgetPerDay);
        $this->assertSame(2000.0, $resolved->budgetPerMonth);
        $this->assertSame(60, $resolved->rateLimitPerMinute);
        $this->assertSame(['gpt-4o', 'claude-sonnet'], $resolved->models);
    }

    public function testResolveRulesWithOverridesRestrictsTeam(): void
    {
        $team = new Team(
            id: 'team-1',
            name: 'Engineering',
            rules: new KeyRules(
                budgetPerDay: 100.0,
                models: ['gpt-4o', 'claude-sonnet', 'deepseek'],
            ),
        );

        $key = new ApiKey(
            id: 'key-1',
            name: 'Restricted Key',
            keyHash: 'hash',
            tokenPrefix: 'aig_1234',
            teamId: 'team-1',
            overrides: new KeyRules(
                budgetPerDay: 20.0,
                models: ['gpt-4o'],
            ),
        );

        $resolved = $key->resolveRules($team);

        $this->assertSame(20.0, $resolved->budgetPerDay);
        $this->assertSame(['gpt-4o'], $resolved->models);
    }

    public function testIsExpiredReturnsFalseWhenNoExpiry(): void
    {
        $key = new ApiKey(
            id: 'key-1',
            name: 'Test',
            keyHash: 'hash',
            tokenPrefix: 'aig_',
        );

        $this->assertFalse($key->isExpired());
    }

    public function testIsExpiredReturnsTrueWhenPastExpiry(): void
    {
        $key = new ApiKey(
            id: 'key-1',
            name: 'Test',
            keyHash: 'hash',
            tokenPrefix: 'aig_',
            expiresAt: time() - 3600,
        );

        $this->assertTrue($key->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenFutureExpiry(): void
    {
        $key = new ApiKey(
            id: 'key-1',
            name: 'Test',
            keyHash: 'hash',
            tokenPrefix: 'aig_',
            expiresAt: time() + 3600,
        );

        $this->assertFalse($key->isExpired());
    }
}
