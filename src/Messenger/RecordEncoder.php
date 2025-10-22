<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Messenger;

use ChargeCloud\AvroMessengerSerializerBundle\Schema\SchemaRepository;
use FlixTech\AvroSerializer\Objects\RecordSerializer;

final class RecordEncoder
{
    public function __construct(
        private readonly RecordSerializer $recordSerializer,
        private readonly SchemaRepository $schemaRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function encode(string $subject, array $payload): string
    {
        $schema = $this->schemaRepository->get($subject);

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
}
