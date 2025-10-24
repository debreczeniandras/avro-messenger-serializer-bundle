<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Messenger;

interface HeaderProviderInterface
{
    /**
     * @return array<string, scalar>
     */
    public function headersForMessage(AvroMessageInterface $message): array;
}
