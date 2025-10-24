<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Tests\Fixtures\Schema;

use FlixTech\SchemaRegistryApi\Exception\SchemaNotFoundException;
use FlixTech\SchemaRegistryApi\Exception\SubjectNotFoundException;
use FlixTech\SchemaRegistryApi\Schema\AvroReference;
use FlixTech\SchemaRegistryApi\SynchronousRegistry;

final class InMemoryRegistry implements SynchronousRegistry
{
    /** @var array<string, array{schema: \AvroSchema, id: int, version: int}> */
    private array $subjects = [];

    /** @var array<int, \AvroSchema> */
    private array $schemasById = [];

    private int $nextId = 1;

    public function register(string $subject, \AvroSchema $schema, AvroReference ...$references): int
    {
        $key = $this->makeSchemaKey($schema);

        if (isset($this->subjects[$subject]) && $this->makeSchemaKey($this->subjects[$subject]['schema']) === $key) {
            return $this->subjects[$subject]['id'];
        }

        $id = $this->nextId++;
        $this->subjects[$subject] = [
            'schema' => $schema,
            'id' => $id,
            'version' => 1,
        ];
        $this->schemasById[$id] = $schema;

        return $id;
    }

    public function schemaVersion(string $subject, \AvroSchema $schema): int
    {
        if (!isset($this->subjects[$subject])) {
            throw new SubjectNotFoundException(\sprintf('Subject %s not found', $subject));
        }

        return $this->subjects[$subject]['version'];
    }

    public function latestVersion(string $subject): \AvroSchema
    {
        if (!isset($this->subjects[$subject])) {
            throw new SubjectNotFoundException(\sprintf('Subject %s not found', $subject));
        }

        return $this->subjects[$subject]['schema'];
    }

    public function schemaId(string $subject, \AvroSchema $schema): int
    {
        if (!isset($this->subjects[$subject])) {
            throw new SubjectNotFoundException(\sprintf('Subject %s not found', $subject));
        }

        $stored = $this->subjects[$subject]['schema'];
        if ($this->makeSchemaKey($stored) !== $this->makeSchemaKey($schema)) {
            throw new SchemaNotFoundException('Schema not found for subject');
        }

        return $this->subjects[$subject]['id'];
    }

    public function schemaForId(int $schemaId): \AvroSchema
    {
        if (!isset($this->schemasById[$schemaId])) {
            throw new SchemaNotFoundException('Schema ID not found');
        }

        return $this->schemasById[$schemaId];
    }

    public function schemaForSubjectAndVersion(string $subject, int $version): \AvroSchema
    {
        if (!isset($this->subjects[$subject])) {
            throw new SubjectNotFoundException(\sprintf('Subject %s not found', $subject));
        }

        return $this->subjects[$subject]['schema'];
    }

    public function schemaForIdAsync(int $schemaId)
    {
        throw new \BadMethodCallException('Asynchronous operations are not supported in tests.');
    }

    public function latestVersionAsync(string $subject)
    {
        throw new \BadMethodCallException('Asynchronous operations are not supported in tests.');
    }

    public function registerAsync(string $subject, \AvroSchema $schema, AvroReference ...$references)
    {
        throw new \BadMethodCallException('Asynchronous operations are not supported in tests.');
    }

    public function schemaVersionAsync(string $subject, \AvroSchema $schema)
    {
        throw new \BadMethodCallException('Asynchronous operations are not supported in tests.');
    }

    public function schemaIdAsync(string $subject, \AvroSchema $schema)
    {
        throw new \BadMethodCallException('Asynchronous operations are not supported in tests.');
    }

    public function schemaForSubjectAndVersionAsync(string $subject, int $version)
    {
        throw new \BadMethodCallException('Asynchronous operations are not supported in tests.');
    }

    private function makeSchemaKey(\AvroSchema $schema): string
    {
        return (string) $schema;
    }
}
