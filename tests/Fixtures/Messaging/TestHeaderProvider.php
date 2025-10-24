<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Tests\Fixtures\Messaging;

use Chargecloud\AvroMessengerSerializerBundle\Messenger\AvroMessageInterface;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\HeaderProviderInterface;

final class TestHeaderProvider implements HeaderProviderInterface
{
    public function headersForMessage(AvroMessageInterface $message): array
    {
        return [
            'test-header' => 'value',
        ];
    }
}
