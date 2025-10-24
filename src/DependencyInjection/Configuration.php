<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('chargecloud_avro_messenger_serializer');
        /** @var \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('schema_dirs')
                    ->info('Directories that contain Avro schema definitions (*.avsc).')
                    ->example(['%kernel.project_dir%/config/avro'])
                    ->prototype('scalar')->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('schema_registry')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('base_uri')
                            ->info('Base URI of the Confluent Schema Registry.')
                            ->defaultValue('%env(string:SCHEMA_REGISTRY_URL)%')
                        ->end()
                        ->arrayNode('auth')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('username')->defaultNull()->end()
                                ->scalarNode('password')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('options')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->floatNode('timeout')->defaultValue(5.0)->end()
                                ->floatNode('connect_timeout')->defaultValue(1.0)->end()
                                ->booleanNode('verify')->defaultTrue()->end()
                            ->end()
                        ->end()
                        ->booleanNode('register_missing_schemas')
                            ->info('Automatically register schemas that are not known to the registry when encoding.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('register_missing_subjects')
                            ->info('Automatically register subjects that are not known to the registry when encoding.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('messages')
                    ->useAttributeAsKey('class')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('key_subject')->defaultNull()->end()
                            ->scalarNode('value_subject')->defaultNull()->end()
                            ->scalarNode('header_provider')->defaultNull()->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end();

        return $treeBuilder;
    }
}
