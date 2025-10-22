<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Messenger;

final class MessageMetadata
{
    public function __construct(
        private readonly string $serviceId,
        private readonly string $className,
        private readonly ?string $keySubject,
        private readonly ?string $valueSubject,
        private readonly ?string $headerProviderServiceId,
    ) {
    }

    public function serviceId(): string
    {
        return $this->serviceId;
    }

    public function className(): string
    {
        return $this->className;
    }

    public function keySubject(): ?string
    {
        return $this->keySubject;
    }

    public function valueSubject(): ?string
    {
        return $this->valueSubject;
    }

    public function allowsTombstone(): bool
    {
        return null === $this->valueSubject;
    }

    public function headerProviderServiceId(): ?string
    {
        return $this->headerProviderServiceId;
    }
}
