<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Tests\Schema;

use Chargecloud\AvroMessengerSerializerBundle\Schema\SchemaLoader;
use PHPUnit\Framework\TestCase;

final class SchemaLoaderTest extends TestCase
{
    public function testDerivesSubjectFromDirectoryStructure(): void
    {
        $directory = __DIR__.'/../Fixtures/config/schema';
        $loader = new SchemaLoader([$directory]);

        $schemas = $loader->load();

        self::assertArrayHasKey('ocpi.queue.session.position-updated-key', $schemas);
        self::assertArrayHasKey('ocpi.queue.session.position-updated-value', $schemas);
    }

    public function testResolvesCrossFileReferencesRegardlessOfDiscoveryOrder(): void
    {
        $directory = __DIR__.'/../Fixtures/config/schema';
        $loader = new SchemaLoader([$directory]);

        $schemas = $loader->load();

        self::assertArrayHasKey('ocpi.GeoLocation', $schemas);
        self::assertArrayHasKey('ocpi.ChargingLocation', $schemas);

        $geoSchema = $schemas['ocpi.GeoLocation'];
        $chargingLocationSchema = $schemas['ocpi.ChargingLocation'];

        $coordinatesField = null;
        foreach ($chargingLocationSchema->fields() as $field) {
            if ('coordinates' === $field->name()) {
                $coordinatesField = $field;

                break;
            }
        }

        self::assertInstanceOf(\AvroField::class, $coordinatesField);

        $coordinatesSchema = $coordinatesField->type();
        self::assertSame(\AvroSchema::UNION_SCHEMA, $coordinatesSchema->type());

        $unionSchemas = $coordinatesSchema->schemas();
        self::assertCount(2, $unionSchemas);
        self::assertSame(\AvroSchema::NULL_TYPE, $unionSchemas[0]->type());
        self::assertSame($geoSchema, $unionSchemas[1]);
    }
}
