<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\DependencyInjection\Compiler;

use Chargecloud\AvroMessengerSerializerBundle\Messenger\AvroMessengerSerializer;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\HeaderProviderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class MessageMetadataCompilerPass implements CompilerPassInterface
{
    public const TAG = 'Chargecloud.avro_messenger_serializer.message_serializer';
    public const HEADER_PROVIDER_TAG = 'Chargecloud.avro_messenger_serializer.header_provider';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('chargecloud_avro_messenger_serializer.message_metadata')) {
            return;
        }

        $metadata = $container->getParameter('chargecloud_avro_messenger_serializer.message_metadata');

        if (!\is_array($metadata)) {
            $metadata = [];
        }

        $providerMap = [];

        foreach ($container->findTaggedServiceIds(self::HEADER_PROVIDER_TAG) as $serviceId => $tags) {
            $definition = $container->findDefinition($serviceId);
            $class = $definition->getClass();

            if (null === $class || !is_a($class, HeaderProviderInterface::class, true)) {
                throw new \InvalidArgumentException(\sprintf('Header provider service "%s" must implement %s.', $serviceId, HeaderProviderInterface::class));
            }

            $providerMap[$serviceId] = new Reference($serviceId);
        }

        foreach ($metadata as $messageClass => &$definition) {
            if (!\is_array($definition)) {
                unset($metadata[$messageClass]);
                continue;
            }

            if (!isset($definition['class']) || !\is_string($definition['class']) || '' === $definition['class']) {
                $definition['class'] = \is_string($messageClass) ? $messageClass : '';
            }

            if (!isset($definition['service_id']) || !\is_string($definition['service_id']) || '' === $definition['service_id']) {
                $definition['service_id'] = AvroMessengerSerializer::class;
            }

            $headerProvider = $definition['header_provider'] ?? null;

            if (null !== $headerProvider) {
                if (!\is_string($headerProvider) || '' === $headerProvider) {
                    throw new \InvalidArgumentException(\sprintf('Header provider for message "%s" must be a non-empty string.', $definition['class']));
                }

                if (!$container->has($headerProvider)) {
                    throw new \InvalidArgumentException(\sprintf('Header provider service "%s" defined for "%s" could not be found.', $headerProvider, $definition['class']));
                }

                $providerDefinition = $container->findDefinition($headerProvider);
                $providerClass = $providerDefinition->getClass();

                if (null === $providerClass || !is_a($providerClass, HeaderProviderInterface::class, true)) {
                    throw new \InvalidArgumentException(\sprintf('Header provider "%s" must implement %s.', $headerProvider, HeaderProviderInterface::class));
                }

                $providerMap[$headerProvider] = new Reference($headerProvider);
            }
        }
        unset($definition);

        foreach ($container->findTaggedServiceIds(self::TAG) as $serviceId => $tags) {
            $definition = $container->findDefinition($serviceId);
            $class = $definition->getClass();

            if (null === $class) {
                continue;
            }

            if (!is_a($class, AvroMessengerSerializer::class, true) && !is_a($class, \Symfony\Component\Messenger\Transport\Serialization\SerializerInterface::class, true)) {
                continue;
            }

            foreach ($tags as $attributes) {
                $messageClass = $attributes['class_name'] ?? $attributes['class'] ?? null;

                if (!\is_string($messageClass) || '' === $messageClass) {
                    throw new \InvalidArgumentException(\sprintf('The "%s" tag requires a non-empty "class_name" attribute.', self::TAG));
                }

                $keySubject = $attributes['key_subject'] ?? null;
                $valueSubject = $attributes['value_subject'] ?? null;
                $headerProvider = $attributes['header_provider'] ?? null;

                if (null !== $headerProvider && !\is_string($headerProvider)) {
                    throw new \InvalidArgumentException(\sprintf('The "%s" tag\'s "header_provider" attribute must be a service id string.', self::TAG));
                }

                if (null !== $headerProvider) {
                    if (!$container->has($headerProvider)) {
                        throw new \InvalidArgumentException(\sprintf('Header provider service "%s" defined for "%s" could not be found.', $headerProvider, $messageClass));
                    }

                    $providerDefinition = $container->findDefinition($headerProvider);
                    $providerClass = $providerDefinition->getClass();

                    if (null === $providerClass || !is_a($providerClass, HeaderProviderInterface::class, true)) {
                        throw new \InvalidArgumentException(\sprintf('Header provider "%s" must implement %s.', $headerProvider, HeaderProviderInterface::class));
                    }

                    $providerMap[$headerProvider] = new Reference($headerProvider);
                }

                $metadata[$messageClass] = [
                    'service_id' => $serviceId,
                    'class' => $messageClass,
                    'key_subject' => null !== $keySubject ? (string) $keySubject : null,
                    'value_subject' => null !== $valueSubject ? (string) $valueSubject : null,
                    'header_provider' => $headerProvider,
                ];
            }
        }

        $container->setParameter('chargecloud_avro_messenger_serializer.message_metadata', $metadata);

        if ($container->hasDefinition(AvroMessengerSerializer::class)) {
            $serializerDefinition = $container->getDefinition(AvroMessengerSerializer::class);

            if ([] === $providerMap) {
                $serializerDefinition->setArgument('$headerProviderLocator', null);
            } else {
                $serviceLocator = ServiceLocatorTagPass::register($container, $providerMap);

                if ($serviceLocator instanceof Reference) {
                    $serializerDefinition->setArgument('$headerProviderLocator', $serviceLocator);
                } else {
                    $serializerDefinition->setArgument('$headerProviderLocator', new Reference((string) $serviceLocator));
                }
            }
        }
    }
}
