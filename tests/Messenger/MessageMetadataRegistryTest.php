<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Tests\Messenger;

use ChargeCloud\AvroMessengerSerializerBundle\Messenger\MessageMetadata;
use ChargeCloud\AvroMessengerSerializerBundle\Messenger\MessageMetadataRegistry;
use ChargeCloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\AttributeMessage;
use ChargeCloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\ConfiguredMessage;
use PHPUnit\Framework\TestCase;

final class MessageMetadataRegistryTest extends TestCase
{
    public function testReturnsConfiguredMetadata(): void
    {
        $registry = new MessageMetadataRegistry([
            ConfiguredMessage::class => [
                'service_id' => 'service',
                'class' => ConfiguredMessage::class,
                'key_subject' => 'configured-key',
                'value_subject' => 'configured-value',
                'header_provider' => null,
            ],
        ]);

        $metadata = $registry->get(ConfiguredMessage::class);

        self::assertInstanceOf(MessageMetadata::class, $metadata);
        self::assertSame('configured-key', $metadata->keySubject());
        self::assertSame('configured-value', $metadata->valueSubject());
    }

    public function testResolvesFromAttribute(): void
    {
        $registry = new MessageMetadataRegistry([]);

        $metadata = $registry->get(AttributeMessage::class);

        self::assertInstanceOf(MessageMetadata::class, $metadata);
        self::assertSame('attribute-key', $metadata->keySubject());
        self::assertSame('attribute-value', $metadata->valueSubject());
    }
}
