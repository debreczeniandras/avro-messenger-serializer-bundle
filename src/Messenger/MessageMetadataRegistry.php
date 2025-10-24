<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Messenger;

use Chargecloud\AvroMessengerSerializerBundle\Attribute\AsAvroMessage;

final class MessageMetadataRegistry
{
    /**
     * @var array<string, MessageMetadata>
     */
    private array $metadata;

    /**
     * @var array<string, bool>
     */
    private array $attributeAttempts = [];

    /**
     * @param array<string, array{service_id: string, class: string, key_subject: string|null, value_subject: string|null, header_provider: string|null}> $rawMetadata
     */
    public function __construct(array $rawMetadata)
    {
        $this->metadata = [];

        foreach ($rawMetadata as $className => $metadata) {
            $this->metadata[$className] = new MessageMetadata(
                $metadata['service_id'],
                $metadata['class'],
                $metadata['key_subject'],
                $metadata['value_subject'],
                $metadata['header_provider']
            );
        }
    }

    public function get(string $className): ?MessageMetadata
    {
        if (isset($this->metadata[$className])) {
            return $this->metadata[$className];
        }

        $attributeMetadata = $this->resolveFromAttribute($className);
        if (null !== $attributeMetadata) {
            $this->metadata[$className] = $attributeMetadata;

            return $attributeMetadata;
        }

        foreach (class_parents($className) as $parent) {
            $parentMetadata = $this->get($parent);

            if (null !== $parentMetadata) {
                return $parentMetadata;
            }
        }

        foreach (class_implements($className) as $interface) {
            $interfaceMetadata = $this->get($interface);

            if (null !== $interfaceMetadata) {
                return $interfaceMetadata;
            }
        }

        return null;
    }

    /**
     * @return MessageMetadata[]
     */
    public function all(): array
    {
        return array_values($this->metadata);
    }

    private function resolveFromAttribute(string $className): ?MessageMetadata
    {
        if (!class_exists($className)) {
            return null;
        }

        if (isset($this->attributeAttempts[$className])) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException) {
            $this->attributeAttempts[$className] = true;

            return null;
        }

        $attributes = $reflection->getAttributes(AsAvroMessage::class);

        if ([] === $attributes) {
            $this->attributeAttempts[$className] = true;

            return null;
        }

        /** @var AsAvroMessage $attribute */
        $attribute = $attributes[0]->newInstance();

        if (!$reflection->implementsInterface(AvroMessageInterface::class)) {
            throw new \LogicException(\sprintf('Class "%s" must implement %s to use the AsAvroMessage attribute.', $className, AvroMessageInterface::class));
        }

        $metadata = new MessageMetadata(
            AvroMessengerSerializer::class,
            $className,
            $attribute->keySubject,
            $attribute->valueSubject,
            $attribute->headerProvider
        );

        $this->attributeAttempts[$className] = true;

        return $metadata;
    }
}
