<?php

declare(strict_types=1);

namespace PhiGateway\Tests\Config;

use PHPUnit\Framework\TestCase;
use PhiGateway\Config\ModelRegistry;
use PhiGateway\Exception\GatewayException;

final class ModelRegistryTest extends TestCase
{
    private ModelRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ModelRegistry([
            'gpt-4o' => [
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'pricing' => ['input' => 2.50, 'output' => 10.00],
            ],
            'gpt-4o-mini' => [
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'pricing' => ['input' => 0.15, 'output' => 0.60],
            ],
            'claude-sonnet' => [
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-20250514',
                'pricing' => ['input' => 3.00, 'output' => 15.00],
            ],
        ]);
    }

    public function testResolveExistingModel(): void
    {
        $resolution = $this->registry->resolve('gpt-4o');

        $this->assertSame('gpt-4o', $resolution->alias);
        $this->assertSame('openai', $resolution->provider);
        $this->assertSame('gpt-4o', $resolution->model);
    }

    public function testResolveModelWithDifferentProviderModelName(): void
    {
        $resolution = $this->registry->resolve('claude-sonnet');

        $this->assertSame('claude-sonnet', $resolution->alias);
        $this->assertSame('anthropic', $resolution->provider);
        $this->assertSame('claude-sonnet-4-20250514', $resolution->model);
    }

    public function testResolveUnknownModelThrowsException(): void
    {
        $this->expectException(GatewayException::class);

        $this->registry->resolve('nonexistent-model');
    }

    public function testHasReturnsTrueForExistingModel(): void
    {
        $this->assertTrue($this->registry->has('gpt-4o'));
    }

    public function testHasReturnsFalseForUnknownModel(): void
    {
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    public function testGetAvailableModels(): void
    {
        $models = $this->registry->getAvailableModels();

        $this->assertCount(3, $models);
        $this->assertContains('gpt-4o', $models);
        $this->assertContains('claude-sonnet', $models);
    }

    public function testAliasResolution(): void
    {
        $this->registry->addAlias('smart', 'gpt-4o');

        $resolution = $this->registry->resolve('smart');

        $this->assertSame('openai', $resolution->provider);
    }

    public function testPricingCalculation(): void
    {
        $resolution = $this->registry->resolve('gpt-4o');

        $cost = $resolution->pricing->calculateCost(1000, 500);

        $this->assertSame(0.0075, $cost);
    }
}
