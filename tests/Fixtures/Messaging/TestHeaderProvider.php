<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Tests\Fixtures\Messaging;

use ChargeCloud\AvroMessengerSerializerBundle\Messenger\AvroMessageInterface;
use ChargeCloud\AvroMessengerSerializerBundle\Messenger\HeaderProviderInterface;

final class TestHeaderProvider implements HeaderProviderInterface
{
    public function headersForMessage(AvroMessageInterface $message): array
    {
        return [
            'test-header' => 'value',
        ];
    }
}
