<?php

declare(strict_types=1);

namespace AIGateway\Tests\Auth\Entity;

use AIGateway\Auth\Entity\KeyRules;
use PHPUnit\Framework\TestCase;

final class KeyRulesTest extends TestCase
{
    public function testMergeRestrictiveTakesMinBudget(): void
    {
        $mine = new KeyRules(budgetPerDay: 20.0);
        $parent = new KeyRules(budgetPerDay: 100.0);

        $result = $mine->mergeRestrictive($parent);

        $this->assertSame(20.0, $result->budgetPerDay);
    }

    public function testMergeRestrictiveInheritsParentWhenNull(): void
    {
        $mine = new KeyRules();
        $parent = new KeyRules(budgetPerDay: 100.0);

        $result = $mine->mergeRestrictive($parent);

        $this->assertSame(100.0, $result->budgetPerDay);
    }

    public function testMergeRestrictiveKeepsMineWhenParentNull(): void
    {
        $mine = new KeyRules(budgetPerDay: 50.0);
        $parent = new KeyRules();

        $result = $mine->mergeRestrictive($parent);

        $this->assertSame(50.0, $result->budgetPerDay);
    }

    public function testMergeRestrictiveIntersectsModels(): void
    {
        $mine = new KeyRules(models: ['gpt-4o', 'deepseek']);
        $parent = new KeyRules(models: ['gpt-4o', 'claude-sonnet', 'deepseek']);

        $result = $mine->mergeRestrictive($parent);

        $this->assertNotNull($result->models);
        $this->assertContains('gpt-4o', $result->models);
        $this->assertContains('deepseek', $result->models);
        $this->assertNotContains('claude-sonnet', $result->models);
    }

    public function testMergeRestrictiveTakesMinRateLimit(): void
    {
        $mine = new KeyRules(rateLimitPerMinute: 10);
        $parent = new KeyRules(rateLimitPerMinute: 60);

        $result = $mine->mergeRestrictive($parent);

        $this->assertSame(10, $result->rateLimitPerMinute);
    }

    public function testIsModelAllowedReturnsTrueWhenNoWhitelist(): void
    {
        $rules = new KeyRules();

        $this->assertTrue($rules->isModelAllowed('anything'));
    }

    public function testIsModelAllowedReturnsTrueForExactMatch(): void
    {
        $rules = new KeyRules(models: ['gpt-4o']);

        $this->assertTrue($rules->isModelAllowed('gpt-4o'));
    }

    public function testIsModelAllowedReturnsTrueForPrefixMatch(): void
    {
        $rules = new KeyRules(models: ['gpt-4']);

        $this->assertTrue($rules->isModelAllowed('gpt-4o'));
        $this->assertTrue($rules->isModelAllowed('gpt-4o-mini'));
    }

    public function testIsModelAllowedReturnsFalseForNonMatch(): void
    {
        $rules = new KeyRules(models: ['gpt-4o']);

        $this->assertFalse($rules->isModelAllowed('claude-sonnet'));
    }
}
