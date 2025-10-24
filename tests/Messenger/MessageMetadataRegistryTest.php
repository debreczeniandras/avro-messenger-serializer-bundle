<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Tests\Messenger;

use Chargecloud\AvroMessengerSerializerBundle\Messenger\MessageMetadata;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\MessageMetadataRegistry;
use Chargecloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\AttributeMessage;
use Chargecloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\ConfiguredMessage;
use PHPUnit\Framework\TestCase;

final class MessageMetadataRegistryTest extends TestCase
{
    public function testReturnsConfiguredMetadata(): void
    {
        $registry = new MessageMetadataRegistry([
            ConfiguredMessage::class => [
                'service_id' => 'service',
                'class' => ConfiguredMessage::class,
                'key_subject' => 'Chargecloud.Tests.ConfiguredKey',
                'value_subject' => 'Chargecloud.Tests.ConfiguredValue',
                'header_provider' => null,
            ],
        ]);

        $metadata = $registry->get(ConfiguredMessage::class);

        self::assertInstanceOf(MessageMetadata::class, $metadata);
        self::assertSame('Chargecloud.Tests.ConfiguredKey', $metadata->keySubject());
        self::assertSame('Chargecloud.Tests.ConfiguredValue', $metadata->valueSubject());
    }

    public function testResolvesFromAttribute(): void
    {
        $registry = new MessageMetadataRegistry([]);

        $metadata = $registry->get(AttributeMessage::class);

        self::assertInstanceOf(MessageMetadata::class, $metadata);
        self::assertSame('Chargecloud.Tests.AttributeKey', $metadata->keySubject());
        self::assertSame('Chargecloud.Tests.AttributeValue', $metadata->valueSubject());
    }
}
