<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message;

use ChargeCloud\AvroMessengerSerializerBundle\Attribute\AsAvroMessage;
use ChargeCloud\AvroMessengerSerializerBundle\Messenger\AvroMessageInterface;

#[AsAvroMessage(
    keySubject: 'ocpi.queue.session.position-updated-key',
    valueSubject: 'ocpi.queue.session.position-updated-value',
)]
final class PositionUpdatedMessage implements AvroMessageInterface
{
    public function __construct(
        private readonly array $key,
        private readonly ?array $value,
    ) {
    }

    public function eventId(): ?string
    {
        $id = $this->value['session_id'] ?? $this->key['session_id'] ?? null;

        return \is_scalar($id) ? (string) $id : null;
    }

    public function eventType(): ?string
    {
        return self::class;
    }

    public static function fromAvroPayload(?array $keyPayload, ?array $valuePayload): AvroMessageInterface
    {
        return new self($keyPayload ?? [], $valuePayload);
    }

    public function avroKeyPayload(): ?array
    {
        return $this->key;
    }

    public function avroValuePayload(): ?array
    {
        return $this->value;
    }
}
