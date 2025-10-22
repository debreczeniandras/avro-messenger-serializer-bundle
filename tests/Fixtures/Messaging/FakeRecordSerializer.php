<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Tests\Fixtures\Messaging;

use ChargeCloud\AvroMessengerSerializerBundle\Tests\Fixtures\Schema\InMemoryRegistry;
use FlixTech\AvroSerializer\Objects\RecordSerializer;

final class FakeRecordSerializer extends RecordSerializer
{
    public function __construct()
    {
        parent::__construct(new InMemoryRegistry(), [
            self::OPTION_REGISTER_MISSING_SCHEMAS => true,
            self::OPTION_REGISTER_MISSING_SUBJECTS => true,
        ]);
    }

    public function encodeRecord(string $subject, \AvroSchema $schema, mixed $record): string
    {
        return json_encode([
            'subject' => $subject,
            'payload' => $record,
        ], \JSON_THROW_ON_ERROR);
    }

    public function decodeMessage(string $binaryMessage, ?\AvroSchema $readersSchema = null): mixed
    {
        $decoded = json_decode($binaryMessage, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded['payload'] ?? [];
    }
}
