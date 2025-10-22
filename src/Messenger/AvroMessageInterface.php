<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Messenger;

interface AvroMessageInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function avroKeyPayload(): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function avroValuePayload(): ?array;

    /**
     * @param array<string, mixed>|null $keyPayload
     * @param array<string, mixed>|null $valuePayload
     */
    public static function fromAvroPayload(?array $keyPayload, ?array $valuePayload): self;
}
