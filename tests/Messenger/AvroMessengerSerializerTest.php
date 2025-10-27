<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Tests\Messenger;

use Chargecloud\AvroMessengerSerializerBundle\Messenger\AvroMessengerSerializer;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\MessageMetadataRegistry;
use Chargecloud\AvroMessengerSerializerBundle\Schema\SchemaRepository;
use Chargecloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\AttributeMessage;
use Chargecloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\ChargePointAssignedMessage;
use Chargecloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\ConfiguredMessage;
use Chargecloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\PositionUpdatedMessage;
use Chargecloud\AvroMessengerSerializerBundle\Tests\Fixtures\Schema\InMemoryRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;

final class AvroMessengerSerializerTest extends KernelTestCase
{
    private AvroMessengerSerializer $serializer;
    private SchemaRepository $schemaRepository;
    private MessageMetadataRegistry $metadataRegistry;
    private InMemoryRegistry $schemaRegistry;

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

        $schemaRegistry = $container->get(InMemoryRegistry::class);
        self::assertInstanceOf(InMemoryRegistry::class, $schemaRegistry);
        $this->schemaRegistry = $schemaRegistry;
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
        self::assertSame('Chargecloud.Tests.ConfiguredValue', $encoded['headers']['x-avro-value-subject']);
        self::assertSame('Chargecloud.Tests.ConfiguredKey', $encoded['headers']['x-avro-key-subject']);
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

        self::assertSame('1', $encoded['headers']['x-is-tombstone']);
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

        self::assertTrue($this->schemaRepository->has('Chargecloud.Tests.AttributeValue'));
        self::assertTrue($this->schemaRepository->has('Chargecloud.Tests.AttributeKey'));
        $metadata = $this->metadataRegistry->get(AttributeMessage::class);
        self::assertNotNull($metadata);
        self::assertSame('Chargecloud.Tests.AttributeValue', $metadata->valueSubject());
        self::assertStringContainsString('AttributeValue', (string) $this->schemaRepository->get('Chargecloud.Tests.AttributeValue'));

        $encoded = $this->serializer->encode($envelope);

        self::assertSame('Chargecloud.Tests.AttributeValue', $encoded['headers']['x-avro-value-subject']);

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

        self::assertSame('ocpi.queue.session.position-updated-key', $encoded['headers']['x-avro-key-subject'] ?? null);
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

    public function testSchemaReferencesRegisteredDuringEncoding(): void
    {
        $message = new ChargePointAssignedMessage(
            ['session_id' => 'session-456'],
            [
                'session_id' => 'session-456',
                'timestamp' => '2024-09-18T12:34:56+00:00',
                'evse_id' => 'EVSE-1',
                'physical_reference' => 'PR-1',
                'coordinates' => [
                    'latitude' => '52.5200',
                    'longitude' => '13.4050',
                ],
                'authorization_timeout' => 90,
            ]
        );

        $envelope = new Envelope($message);

        $encoded = $this->serializer->encode($envelope);

        self::assertSame('ocpi.queue.session.charge-point-assigned-value', $encoded['headers']['x-avro-value-subject'] ?? null);
        self::assertNotEmpty($encoded['body']);

        $geoSchema = $this->schemaRepository->get('ocpi.geo-location');
        $geoSchemaId = $this->schemaRegistry->schemaId('ocpi.geo-location', $geoSchema);
        self::assertGreaterThan(0, $geoSchemaId);

        $references = $this->schemaRegistry->referencesFor('ocpi.queue.session.charge-point-assigned-value');
        self::assertCount(1, $references);

        $referenceData = $references[0]->jsonSerialize();
        self::assertSame('ocpi.GeoLocation', $referenceData['name']);
        self::assertSame('ocpi.geo-location', $referenceData['subject']);

        $decodedEnvelope = $this->serializer->decode($encoded);
        /** @var ChargePointAssignedMessage $decodedMessage */
        $decodedMessage = $decodedEnvelope->getMessage();

        self::assertSame($message->avroValuePayload(), $decodedMessage->avroValuePayload());
    }
}
