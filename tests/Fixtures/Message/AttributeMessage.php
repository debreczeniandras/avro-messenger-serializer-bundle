<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Tests\Fixtures\Message;

use ChargeCloud\AvroMessengerSerializerBundle\Attribute\AsAvroMessage;
use ChargeCloud\AvroMessengerSerializerBundle\Messenger\AvroMessageInterface;

#[AsAvroMessage(keySubject: 'attribute-key', valueSubject: 'attribute-value')]
final class AttributeMessage implements AvroMessageInterface
{
    public function __construct(
        private readonly array $key,
        private readonly ?array $value,
    ) {
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
