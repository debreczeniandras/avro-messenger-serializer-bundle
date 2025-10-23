<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Tests\Schema;

use ChargeCloud\AvroMessengerSerializerBundle\Schema\SchemaLoader;
use PHPUnit\Framework\TestCase;

final class SchemaLoaderTest extends TestCase
{
    public function testDerivesSubjectFromDirectoryStructure(): void
    {
        $directory = __DIR__.'/../Fixtures/schema';
        $loader = new SchemaLoader([$directory]);

        $schemas = $loader->load();

        self::assertArrayHasKey('ocpi.queue.session.position-updated-key', $schemas);
        self::assertArrayHasKey('ocpi.queue.session.position-updated-value', $schemas);
    }
}
