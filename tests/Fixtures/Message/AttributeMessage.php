<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message;

use Chargecloud\AvroMessengerSerializerBundle\Attribute\AsAvroMessage;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\AvroMessageInterface;

#[AsAvroMessage(
    keySubject: 'Chargecloud.Tests.AttributeKey',
    valueSubject: 'Chargecloud.Tests.AttributeValue'
)]
final class AttributeMessage implements AvroMessageInterface
{
    public function __construct(
        private readonly array $key,
        private readonly ?array $value,
    ) {
    }

    public function eventId(): ?string
    {
        $id = $this->value['id'] ?? $this->key['id'] ?? null;

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
