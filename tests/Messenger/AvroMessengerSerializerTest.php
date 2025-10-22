<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Tests\Messenger;

use ChargeCloud\AvroMessengerSerializerBundle\Messenger\AvroMessengerSerializer;
use ChargeCloud\AvroMessengerSerializerBundle\Messenger\MessageMetadataRegistry;
use ChargeCloud\AvroMessengerSerializerBundle\Schema\SchemaRepository;
use ChargeCloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\AttributeMessage;
use ChargeCloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message\ConfiguredMessage;
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
        self::assertSame('configured-value', $encoded['headers']['x-chargecloud-avro-value-subject']);
        self::assertSame('configured-key', $encoded['headers']['x-chargecloud-avro-key-subject']);
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
        self::assertSame('', $encoded['body']);

        $decodedEnvelope = $this->serializer->decode($encoded);

        /** @var ConfiguredMessage $decodedMessage */
        $decodedMessage = $decodedEnvelope->getMessage();

        self::assertNull($decodedMessage->avroValuePayload());
    }

    public function testAttributeMessageGetsMetadataFromAttribute(): void
    {
        $message = new AttributeMessage(['id' => 'key-2'], ['id' => 'value-2', 'status' => 'ok']);
        $envelope = new Envelope($message);

        self::assertTrue($this->schemaRepository->has('attribute-value'));
        self::assertTrue($this->schemaRepository->has('attribute-key'));
        $metadata = $this->metadataRegistry->get(AttributeMessage::class);
        self::assertNotNull($metadata);
        self::assertSame('attribute-value', $metadata->valueSubject());
        self::assertStringContainsString('AttributeValue', (string) $this->schemaRepository->get('attribute-value'));

        $encoded = $this->serializer->encode($envelope);

        self::assertSame('attribute-value', $encoded['headers']['x-chargecloud-avro-value-subject']);

        $decodedEnvelope = $this->serializer->decode($encoded);
        /** @var AttributeMessage $decodedMessage */
        $decodedMessage = $decodedEnvelope->getMessage();

        self::assertSame(['id' => 'value-2', 'status' => 'ok'], $decodedMessage->avroValuePayload());
    }
}
