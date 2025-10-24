<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Tests\Messenger;

use ChargeCloud\AvroMessengerSerializerBundle\Messenger\AvroMessengerSerializer;
use ChargeCloud\AvroMessengerSerializerBundle\Messenger\MessageMetadataRegistry;
use ChargeCloud\AvroMessengerSerializerBundle\Schema\SchemaRepository;
use ChargeCloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\AttributeMessage;
use ChargeCloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\ConfiguredMessage;
use ChargeCloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\PositionUpdatedMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;

final class AvroMessengerSerializerTest extends KernelTestCase
{
    private AvroMessengerSerializer $serializer;
    private SchemaRepository $schemaRepository;
    private MessageMetadataRegistry $metadataRegistry;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $serializer = $container->get(AvroMessengerSerializer::class);
        self::assertInstanceOf(AvroMessengerSerializer::class, $serializer);
        $this->serializer = $serializer;

        $schemaRepository = $container->get(SchemaRepository::class);
        self::assertInstanceOf(SchemaRepository::class, $schemaRepository);
        $this->schemaRepository = $schemaRepository;

        $metadataRegistry = $container->get(MessageMetadataRegistry::class);
        self::assertInstanceOf(MessageMetadataRegistry::class, $metadataRegistry);
        $this->metadataRegistry = $metadataRegistry;
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
    }

    public function testEncodeDecodeConfiguredMessage(): void
    {
        $message = new ConfiguredMessage(['id' => 'key-1'], ['id' => 'value-1', 'name' => 'Test']);
        $envelope = new Envelope($message);

        $encoded = $this->serializer->encode($envelope);

        self::assertArrayHasKey('body', $encoded);
        self::assertArrayHasKey('headers', $encoded);
        self::assertSame('ChargeCloud.Tests.ConfiguredValue', $encoded['headers']['x-chargecloud-avro-value-subject']);
        self::assertSame('ChargeCloud.Tests.ConfiguredKey', $encoded['headers']['x-chargecloud-avro-key-subject']);
        self::assertSame('value', $encoded['headers']['test-header']);

        $decodedEnvelope = $this->serializer->decode($encoded);

        /** @var ConfiguredMessage $decodedMessage */
        $decodedMessage = $decodedEnvelope->getMessage();

        self::assertInstanceOf(ConfiguredMessage::class, $decodedMessage);
        self::assertSame(['id' => 'key-1'], $decodedMessage->avroKeyPayload());
        self::assertSame(['id' => 'value-1', 'name' => 'Test'], $decodedMessage->avroValuePayload());
    }

    public function testTombstoneWhenValueNull(): void
    {
        $message = new ConfiguredMessage(['id' => 'key-1'], null);
        $envelope = new Envelope($message);

        $encoded = $this->serializer->encode($envelope);

        self::assertSame('1', $encoded['headers']['x-chargecloud-avro-tombstone']);
        self::assertNull($encoded['body']);

        $decodedEnvelope = $this->serializer->decode($encoded);

        /** @var ConfiguredMessage $decodedMessage */
        $decodedMessage = $decodedEnvelope->getMessage();

        self::assertNull($decodedMessage->avroValuePayload());
    }

    public function testAttributeMessageGetsMetadataFromAttribute(): void
    {
        $message = new AttributeMessage(['id' => 'key-2'], ['id' => 'value-2', 'status' => 'ok']);
        $envelope = new Envelope($message);

        self::assertTrue($this->schemaRepository->has('ChargeCloud.Tests.AttributeValue'));
        self::assertTrue($this->schemaRepository->has('ChargeCloud.Tests.AttributeKey'));
        $metadata = $this->metadataRegistry->get(AttributeMessage::class);
        self::assertNotNull($metadata);
        self::assertSame('ChargeCloud.Tests.AttributeValue', $metadata->valueSubject());
        self::assertStringContainsString('AttributeValue', (string) $this->schemaRepository->get('ChargeCloud.Tests.AttributeValue'));

        $encoded = $this->serializer->encode($envelope);

        self::assertSame('ChargeCloud.Tests.AttributeValue', $encoded['headers']['x-chargecloud-avro-value-subject']);

        $decodedEnvelope = $this->serializer->decode($encoded);
        /** @var AttributeMessage $decodedMessage */
        $decodedMessage = $decodedEnvelope->getMessage();

        self::assertSame(['id' => 'value-2', 'status' => 'ok'], $decodedMessage->avroValuePayload());
    }

    public function testPositionUpdatedMessageEncodesKeyPayload(): void
    {
        $keyPayload = ['session_id' => 'session-123'];
        $valuePayload = [
            'session_id' => 'session-123',
            'queue_position' => 5,
            'estimated_wait_seconds' => 120,
        ];
        $message = new PositionUpdatedMessage($keyPayload, $valuePayload);
        $envelope = new Envelope($message);

        $encoded = $this->serializer->encode($envelope);

        self::assertSame('ocpi.queue.session.position-updated-key', $encoded['headers']['x-chargecloud-avro-key-subject'] ?? null);
        self::assertArrayHasKey('key', $encoded);
        self::assertIsString($encoded['key']);

        $decodedKey = json_decode($encoded['key'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decodedKey);
        self::assertSame('ocpi.queue.session.position-updated-key', $decodedKey['subject'] ?? null);
        self::assertSame($keyPayload, $decodedKey['payload'] ?? null);

        $decodedEnvelope = $this->serializer->decode($encoded);

        /** @var PositionUpdatedMessage $decodedMessage */
        $decodedMessage = $decodedEnvelope->getMessage();

        self::assertSame($keyPayload, $decodedMessage->avroKeyPayload());
        self::assertSame($valuePayload, $decodedMessage->avroValuePayload());
    }
}
