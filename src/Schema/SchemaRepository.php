<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Schema;

final class SchemaRepository
{
    /**
     * @var array<string, \AvroSchema>
     */
    private array $schemas;

    public function __construct(private readonly SchemaLoader $schemaLoader)
    {
        $this->schemas = $this->schemaLoader->load();
    }

    public function refresh(): void
    {
        $this->schemas = $this->schemaLoader->load();
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
}
