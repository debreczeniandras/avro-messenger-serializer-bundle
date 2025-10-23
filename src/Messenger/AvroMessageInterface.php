<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Messenger;

/**
 * Contract for messages that can be serialized through the Avro messenger serializer.
 *
 * Implementations expose the logical Avro key/value payloads as well as optional event metadata
 * that can be forwarded through transport headers.
 */
interface AvroMessageInterface
{
    /**
     * Unique identifier for the logical event (for example a CloudEvents `id`).
     *
     * Returning null indicates that the message does not expose a dedicated identifier, leaving
     * header providers free to fallback to transport defaults.
     */
    public function eventId(): ?string;

    /**
     * Domain or integration specific event type (for example a CloudEvents `type`).
     *
     * Returning null allows consumers to determine their own default event type naming strategy.
     */
    public function eventType(): ?string;

    /**
     * Structured data that should be encoded into the Avro key record.
     *
     * @return array<string, mixed>|null returning null skips key serialization for the message
     */
    public function avroKeyPayload(): ?array;

    /**
     * Structured data that should be encoded into the Avro value record.
     *
     * @return array<string, mixed>|null returning null marks the message as a tombstone
     */
    public function avroValuePayload(): ?array;

    /**
     * Restore a message instance from decoded Avro payloads.
     *
     * @param array<string, mixed>|null $keyPayload
     * @param array<string, mixed>|null $valuePayload
     */
    public static function fromAvroPayload(?array $keyPayload, ?array $valuePayload): self;
}
