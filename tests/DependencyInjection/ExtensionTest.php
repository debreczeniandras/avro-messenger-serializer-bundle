<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Tests\DependencyInjection;

use Chargecloud\AvroMessengerSerializerBundle\Messenger\AvroMessengerSerializer;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\MessageMetadataRegistry;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\RecordEncoder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExtensionTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
    }

    public function testServicesAreRegistered(): void
    {
        $container = static::getContainer();

        self::assertTrue($container->has(AvroMessengerSerializer::class));
        self::assertTrue($container->has(RecordEncoder::class));
        self::assertTrue($container->has(MessageMetadataRegistry::class));
    }
}
