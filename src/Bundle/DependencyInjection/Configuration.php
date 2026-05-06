<?php

declare(strict_types=1);

namespace PhiGateway\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('phi_gateway');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('default_model')
                    ->defaultNull()
                ->end()

                ->arrayNode('providers')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('api_key')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('base_url')
                                ->defaultNull()
                            ->end()
                            ->integerNode('timeout_seconds')
                                ->defaultValue(30)
                            ->end()
                            ->scalarNode('organization')
                                ->defaultNull()
                            ->end()
                        ->end()
                    ->end()
                ->end()

                ->arrayNode('models')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('provider')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('model')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->integerNode('max_tokens')
                                ->defaultValue(128000)
                            ->end()
                            ->arrayNode('pricing')
                                ->children()
                                    ->floatNode('input')
                                        ->defaultValue(0.0)
                                    ->end()
                                    ->floatNode('output')
                                        ->defaultValue(0.0)
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()

                ->arrayNode('pipelines')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('models')
                                ->requiresAtLeastOneElement()
                                ->scalarPrototype()->cannotBeEmpty()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()

                ->arrayNode('aliases')
                    ->useAttributeAsKey('name')
                    ->scalarPrototype()->end()
                ->end()

                ->arrayNode('retry')
                    ->children()
                        ->integerNode('max_attempts')
                            ->defaultValue(2)
                        ->end()
                        ->integerNode('delay_ms')
                            ->defaultValue(1000)
                        ->end()
                        ->enumNode('backoff')
                            ->values(['fixed', 'exponential'])
                            ->defaultValue('exponential')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
