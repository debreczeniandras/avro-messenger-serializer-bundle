<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\DependencyInjection;

use Chargecloud\AvroMessengerSerializerBundle\DependencyInjection\Compiler\MessageMetadataCompilerPass;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\AvroMessengerSerializer;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\HeaderProviderInterface;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\MessageMetadataRegistry;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\RecordEncoder;
use Chargecloud\AvroMessengerSerializerBundle\Schema\SchemaLoader;
use Chargecloud\AvroMessengerSerializerBundle\Schema\SchemaRepository;
use FlixTech\AvroSerializer\Objects\RecordSerializer;
use FlixTech\SchemaRegistryApi\Registry\BlockingRegistry;
use FlixTech\SchemaRegistryApi\Registry\Cache\AvroObjectCacheAdapter;
use FlixTech\SchemaRegistryApi\Registry\CachedRegistry;
use FlixTech\SchemaRegistryApi\Registry\PromisingRegistry;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class ChargecloudAvroMessengerSerializerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->registerForAutoconfiguration(HeaderProviderInterface::class)
            ->addTag(MessageMetadataCompilerPass::HEADER_PROVIDER_TAG);

        $container->setParameter('chargecloud_avro_messenger_serializer.schema_dirs', $config['schema_dirs']);
        $container->setParameter('chargecloud_avro_messenger_serializer.register_missing_schemas', $config['schema_registry']['register_missing_schemas']);
        $container->setParameter('chargecloud_avro_messenger_serializer.register_missing_subjects', $config['schema_registry']['register_missing_subjects']);
        $container->setParameter('chargecloud_avro_messenger_serializer.message_metadata', $this->normalizeConfiguredMessages($config['messages'] ?? []));

        $this->registerHttpClient($container, $config);
        $this->registerSchemaRegistry($container, $config);
        $this->registerSchemaInfrastructure($container);
        $this->registerMessengerSerializer($container);
    }

    private function registerHttpClient(ContainerBuilder $container, array $config): void
    {
        $clientDefinition = (new Definition(Client::class))
            ->setArgument('$config', $this->createHttpClientConfig($config));

        $container->setDefinition('chargecloud_avro_messenger_serializer.http_client', $clientDefinition);
    }

    private function createHttpClientConfig(array $config): array
    {
        $clientConfig = [
            'base_uri' => $config['schema_registry']['base_uri'],
            'timeout' => $config['schema_registry']['options']['timeout'],
            'connect_timeout' => $config['schema_registry']['options']['connect_timeout'],
            'verify' => $config['schema_registry']['options']['verify'],
        ];

        $username = $config['schema_registry']['auth']['username'];
        $password = $config['schema_registry']['auth']['password'];

        if (null !== $username && null !== $password) {
            $clientConfig['auth'] = [$username, $password];
        }

        return $clientConfig;
    }

    private function registerSchemaRegistry(ContainerBuilder $container, array $config): void
    {
        $promising = (new Definition(PromisingRegistry::class))
            ->setArgument('$client', new Reference('chargecloud_avro_messenger_serializer.http_client'));
        $container->setDefinition('chargecloud_avro_messenger_serializer.registry.promising', $promising);

        $blocking = (new Definition(BlockingRegistry::class))
            ->setArgument('$registry', new Reference('chargecloud_avro_messenger_serializer.registry.promising'));
        $container->setDefinition('chargecloud_avro_messenger_serializer.registry.blocking', $blocking);

        $cachedRegistry = (new Definition(CachedRegistry::class))
            ->setArgument('$registry', new Reference('chargecloud_avro_messenger_serializer.registry.blocking'))
            ->setArgument('$cacheAdapter', new Definition(AvroObjectCacheAdapter::class));
        $container->setDefinition('chargecloud_avro_messenger_serializer.registry.cached', $cachedRegistry);

        $recordSerializer = (new Definition(RecordSerializer::class))
            ->setArgument('$registry', new Reference('chargecloud_avro_messenger_serializer.registry.cached'))
            ->setArgument('$options', [
                RecordSerializer::OPTION_REGISTER_MISSING_SCHEMAS => $config['schema_registry']['register_missing_schemas'],
                RecordSerializer::OPTION_REGISTER_MISSING_SUBJECTS => $config['schema_registry']['register_missing_subjects'],
            ]);
        $container->setDefinition(RecordSerializer::class, $recordSerializer);
    }

    private function registerSchemaInfrastructure(ContainerBuilder $container): void
    {
        $schemaLoader = (new Definition(SchemaLoader::class))
            ->setArgument('$directories', '%chargecloud_avro_messenger_serializer.schema_dirs%');
        $container->setDefinition(SchemaLoader::class, $schemaLoader);

        $schemaRepository = (new Definition(SchemaRepository::class))
            ->setArgument('$schemaLoader', new Reference(SchemaLoader::class));
        $container->setDefinition(SchemaRepository::class, $schemaRepository);

        $recordEncoder = (new Definition(RecordEncoder::class))
            ->setArgument('$recordSerializer', new Reference(RecordSerializer::class))
            ->setArgument('$schemaRepository', new Reference(SchemaRepository::class))
            ->setArgument('$schemaRegistry', new Reference('chargecloud_avro_messenger_serializer.registry.cached'))
            ->setArgument('$registerMissingSchemas', '%chargecloud_avro_messenger_serializer.register_missing_schemas%')
            ->setArgument('$registerMissingSubjects', '%chargecloud_avro_messenger_serializer.register_missing_subjects%');
        $container->setDefinition(RecordEncoder::class, $recordEncoder);

        $metadataRegistry = (new Definition(MessageMetadataRegistry::class))
            ->setArgument('$rawMetadata', '%chargecloud_avro_messenger_serializer.message_metadata%');
        $container->setDefinition(MessageMetadataRegistry::class, $metadataRegistry);
    }

    private function registerMessengerSerializer(ContainerBuilder $container): void
    {
        $serializer = (new Definition(AvroMessengerSerializer::class))
            ->setArgument('$metadataRegistry', new Reference(MessageMetadataRegistry::class))
            ->setArgument('$recordEncoder', new Reference(RecordEncoder::class))
            ->setArgument('$headerProviderLocator', null)
            ->setArgument('$container', new Reference('service_container'));

        $serializer->setPublic(true);

        $container->setDefinition(AvroMessengerSerializer::class, $serializer);
        $container->setAlias('chargecloud_avro_messenger_serializer.serializer', AvroMessengerSerializer::class)->setPublic(true);
    }

    /**
     * @param array<string, array<string, mixed>> $messages
     *
     * @return array<string, array{service_id: string, class: string, key_subject: string|null, value_subject: string|null, header_provider: string|null}>
     */
    private function normalizeConfiguredMessages(array $messages): array
    {
        $normalized = [];

        foreach ($messages as $className => $options) {
            if (!\is_string($className) || '' === $className) {
                throw new \InvalidArgumentException('Configured message class names must be non-empty strings.');
            }

            if (!\is_array($options)) {
                throw new \InvalidArgumentException(\sprintf('Configuration for message "%s" must be an array.', $className));
            }

            $normalized[$className] = [
                'service_id' => AvroMessengerSerializer::class,
                'class' => $className,
                'key_subject' => isset($options['key_subject']) && '' !== $options['key_subject'] ? (string) $options['key_subject'] : null,
                'value_subject' => isset($options['value_subject']) && '' !== $options['value_subject'] ? (string) $options['value_subject'] : null,
                'header_provider' => isset($options['header_provider']) && '' !== $options['header_provider'] ? (string) $options['header_provider'] : null,
            ];
        }

        return $normalized;
    }
}
