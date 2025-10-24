<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsAvroMessage
{
    public function __construct(
        public readonly ?string $keySubject = null,
        public readonly ?string $valueSubject = null,
        public readonly ?string $headerProvider = null,
    ) {
    }
}
