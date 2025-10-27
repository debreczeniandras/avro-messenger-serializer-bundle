<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Messenger;

use Chargecloud\AvroMessengerSerializerBundle\Schema\SchemaRepository;
use FlixTech\AvroSerializer\Objects\RecordSerializer;
use FlixTech\SchemaRegistryApi\Exception\SchemaNotFoundException;
use FlixTech\SchemaRegistryApi\Exception\SchemaRegistryException;
use FlixTech\SchemaRegistryApi\Exception\SubjectNotFoundException;
use FlixTech\SchemaRegistryApi\Registry;
use FlixTech\SchemaRegistryApi\Schema\AvroName;
use FlixTech\SchemaRegistryApi\Schema\AvroReference;
use GuzzleHttp\Promise\PromiseInterface;

final class RecordEncoder
{
    /** @var array<string, bool> */
    private array $registeredSubjects = [];

    /** @var array<string, bool> */
    private array $registeringSubjects = [];

    public function __construct(
        private readonly RecordSerializer $recordSerializer,
        private readonly SchemaRepository $schemaRepository,
        private readonly Registry $schemaRegistry,
        private readonly bool $registerMissingSchemas,
        private readonly bool $registerMissingSubjects,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function encode(string $subject, array $payload): string
    {
        $schema = $this->schemaRepository->get($subject);
        $this->ensureSchemaRegistered($subject, $schema);

        return $this->recordSerializer->encodeRecord($subject, $schema, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $subject, string $binaryPayload): array
    {
        $schema = $this->schemaRepository->get($subject);
        $decoded = $this->recordSerializer->decodeMessage($binaryPayload, $schema);

        if (!\is_array($decoded)) {
            $decoded = $this->normalizeDecoded($decoded);
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('Decoded Avro payload for subject "%s" is not an array structure.', $subject));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeDecoded(mixed $decoded): ?array
    {
        if ($decoded instanceof \ArrayObject) {
            return $decoded->getArrayCopy();
        }

        if (\is_object($decoded)) {
            try {
                return json_decode(
                    json_encode($decoded, \JSON_THROW_ON_ERROR),
                    true,
                    512,
                    \JSON_THROW_ON_ERROR
                );
            } catch (\JsonException $exception) {
                throw new \RuntimeException('Unable to normalize decoded Avro payload.', 0, $exception);
            }
        }

        return null;
    }

    private function ensureSchemaRegistered(string $subject, \AvroSchema $schema): void
    {
        if (isset($this->registeredSubjects[$subject]) || isset($this->registeringSubjects[$subject])) {
            return;
        }

        try {
            $this->resolveRegistryResponse($this->schemaRegistry->schemaId($subject, $schema));
            $this->registeredSubjects[$subject] = true;

            return;
        } catch (SubjectNotFoundException $exception) {
            if (!$this->registerMissingSubjects) {
                throw $exception;
            }
        } catch (SchemaNotFoundException $exception) {
            if (!$this->registerMissingSchemas) {
                throw $exception;
            }
        }

        $this->registeringSubjects[$subject] = true;

        $references = $this->buildReferences($subject);
        $this->resolveRegistryResponse($this->schemaRegistry->register($subject, $schema, ...$references));

        $this->registeredSubjects[$subject] = true;
        unset($this->registeringSubjects[$subject]);
    }

    /**
     * @return AvroReference[]
     */
    private function buildReferences(string $subject): array
    {
        $references = [];

        foreach ($this->schemaRepository->references($subject) as $referenceSubject) {
            $referenceSchema = $this->schemaRepository->get($referenceSubject);
            $this->ensureSchemaRegistered($referenceSubject, $referenceSchema);

            $version = $this->resolveRegistryResponse(
                $this->schemaRegistry->schemaVersion($referenceSubject, $referenceSchema)
            );

            if (!\is_int($version) && !(\is_string($version) && '' !== $version)) {
                throw new \RuntimeException(\sprintf('Unable to determine version for referenced subject "%s".', $referenceSubject));
            }

            $fullName = $this->schemaRepository->fullName($referenceSubject);

            if (null === $fullName) {
                $fullName = $this->deriveFullName($referenceSchema);
            }

            if (null === $fullName) {
                throw new \RuntimeException(\sprintf('Unable to resolve Avro name for referenced subject "%s".', $referenceSubject));
            }

            $references[] = new AvroReference(
                new AvroName($fullName),
                $referenceSubject,
                $version
            );
        }

        return $references;
    }

    private function deriveFullName(\AvroSchema $schema): ?string
    {
        if (method_exists($schema, 'fullname')) {
            /** @var callable $callable */
            $callable = [$schema, 'fullname'];

            $fullName = $callable();

            if (\is_string($fullName) && '' !== $fullName) {
                return $fullName;
            }
        }

        return null;
    }

    private function resolveRegistryResponse(mixed $response): mixed
    {
        if ($response instanceof PromiseInterface) {
            $response = $response->wait();
        }

        if ($response instanceof SchemaRegistryException) {
            throw $response;
        }

        if ($response instanceof \Exception) {
            throw $response;
        }

        return $response;
    }
}
