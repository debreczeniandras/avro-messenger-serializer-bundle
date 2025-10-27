<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Schema;

final class SchemaRepository
{
    /**
     * @var array<string, \AvroSchema>
     */
    private array $schemas;

    /**
     * @var array<string, string[]>
     */
    private array $references = [];

    /**
     * @var array<string, string>
     */
    private array $fullNames = [];

    public function __construct(private readonly SchemaLoader $schemaLoader)
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->schemas = $this->schemaLoader->load();
        $this->references = $this->schemaLoader->references();
        $this->fullNames = $this->schemaLoader->fullNames();
    }

    public function has(string $subject): bool
    {
        return \array_key_exists($subject, $this->schemas);
    }

    public function get(string $subject): \AvroSchema
    {
        if (!$this->has($subject)) {
            throw new \RuntimeException(\sprintf('Avro schema for subject "%s" could not be found.', $subject));
        }

        return $this->schemas[$subject];
    }

    /**
     * @return array<string, \AvroSchema>
     */
    public function all(): array
    {
        return $this->schemas;
    }

    /**
     * @return string[]
     */
    public function references(string $subject): array
    {
        return $this->references[$subject] ?? [];
    }

    public function fullName(string $subject): ?string
    {
        return $this->fullNames[$subject] ?? null;
    }
}
