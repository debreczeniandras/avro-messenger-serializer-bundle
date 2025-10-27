# Chargecloud Avro Messenger Serializer Bundle

A Symfony bundle that integrates the [flix-tech/avro-serde-php](https://github.com/flix-tech/avro-serde-php) serializer and the [Confluent Schema Registry API](https://github.com/flix-tech/confluent-schema-registry-api) to provide Avro-based serialization for Symfony Messenger messages with automatic schema registration and validation.

## Installation

```bash
composer require Chargecloud/avro-messenger-serializer-bundle
```

Add the bundle to `config/bundles.php` when not using Symfony Flex:

```php
return [
    // ...
    Chargecloud\AvroMessengerSerializerBundle\ChargecloudAvroMessengerSerializerBundle::class => ['all' => true],
];
```

To bootstrap local development, run the bundled Makefile targets:

```bash
make        # installs Composer dependencies inside the Docker composer image
make tests  # runs phpunit followed by php-cs-fixer in dry-run mode
```

## Configuration

Create `config/packages/chargecloud_avro_messenger_serializer.yaml`:

```yaml
chargecloud_avro_messenger_serializer:
  schema_dirs:
    - '%kernel.project_dir%/config/avro'
  schema_registry:
    base_uri: '%env(string:SCHEMA_REGISTRY_URL)%'
    auth:
      username: '%env(default::SCHEMA_REGISTRY_USERNAME)%'
      password: '%env(default::SCHEMA_REGISTRY_PASSWORD)%'
    options:
      timeout: 5
      connect_timeout: 1
      verify: true
    register_missing_schemas: true
    register_missing_subjects: true
  messages:
    App\Message\CustomerUpdated:
      key_subject: 'customer-key'
      value_subject: 'customer-value'
      header_provider: 'App\Infrastructure\Kafka\Headers\CustomerHeaderProvider'
```

- `schema_dirs` is an array of folders that will be scanned (recursively) for `.avsc` files. Each schema file is parsed and made available by its filename, `name`, `namespace.name`, or an explicit `"subject"` key inside the schema JSON.
- `register_missing_schemas` / `register_missing_subjects` toggle the behaviour of the RecordSerializer when the registry does not know a schema or subject yet.
- The registry URL can be injected via `SCHEMA_REGISTRY_URL`.
- `messages` maps message classes to key/value subjects (and optional header providers). These entries feed the serializer metadata directly—no manual service tags required when you use the default serializer.
- Omit the `messages` section entirely when you rely solely on the `#[AsAvroMessage]` attribute.
- Set `value_subject` to `~` (null) when you want the serializer to emit tombstone payloads for that message.

## Declaring Messages

Messages that should be serialized must implement `Chargecloud\AvroMessengerSerializerBundle\Messenger\AvroMessageInterface`:

```php
use Chargecloud\AvroMessengerSerializerBundle\Messenger\AvroMessageInterface;

final class CustomerUpdated implements AvroMessageInterface
{
    public function __construct(
        private readonly array $key,
        private readonly array $payload,
    ) {
    }

    public function eventId(): ?string
    {
        return $this->payload['event_id'] ?? $this->key['id'] ?? null;
    }

    public function eventType(): ?string
    {
        return 'customer.updated';
    }

    public static function fromAvroPayload(?array $keyPayload, ?array $valuePayload): self
    {
        return new self($keyPayload ?? [], $valuePayload ?? []);
    }

    public function avroKeyPayload(): ?array
    {
        return $this->key;
    }

    public function avroValuePayload(): ?array
    {
        return $this->payload;
    }
}
```

`eventId()` and `eventType()` expose optional CloudEvents-inspired metadata that header providers can re-use when building transport headers.

`fromAvroPayload()` is used during decoding. Return `null` from `avroValuePayload()` to emit tombstone messages when the serializer encounters a nullable value subject.

## Wiring the Messenger Serializer

Register the provided serializer service; Messenger will use the configuration metadata:

```yaml
# config/services.yaml
services:
  Chargecloud\AvroMessengerSerializerBundle\Messenger\AvroMessengerSerializer: ~
```

Point your Messenger transport to the alias `chargecloud_avro_messenger_serializer.serializer` (set by the bundle) or directly to the serializer service id:

```yaml
# config/packages/messenger.yaml
framework:
  messenger:
    transports:
      kafka:
        dsn: '%env(KAFKA_TRANSPORT_DSN)%'
        serializer: 'chargecloud_avro_messenger_serializer.serializer'
```

Multiple messages can be declared by listing them under the `messages` configuration. Inheritance and interface metadata are supported; the registry will walk parent classes and interfaces when resolving metadata.

> **Advanced:** When you register additional serializer services (for example, separate services per transport), continue using the `Chargecloud.avro_messenger_serializer.message_serializer` tag on those services to override or extend the configuration. The tag accepts the same `class_name`, `key_subject`, `value_subject`, and optional `header_provider` attributes. Header providers declared as services are automatically tagged with `Chargecloud.avro_messenger_serializer.header_provider`.

### Attribute-based metadata

Instead of configuring each message, you can annotate the DTO with the provided attribute:

```php
use App\Infrastructure\Kafka\Headers\CustomerHeaderProvider;
use Chargecloud\AvroMessengerSerializerBundle\Attribute\AsAvroMessage;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\AvroMessageInterface;

#[AsAvroMessage(keySubject: 'customer-key', valueSubject: 'customer-value', headerProvider: CustomerHeaderProvider::class)]
final class CustomerUpdated implements AvroMessageInterface
{
    // ... implementation ...
}
```

Leave `valueSubject` at its default (`null`) to allow tombstone messages to be emitted. Header providers referenced in the attribute must be registered as services (the service id or class name is accepted).

### Custom headers

Implement `Chargecloud\AvroMessengerSerializerBundle\Messenger\HeaderProviderInterface` when you need to add CloudEvents metadata or tracing headers:

```php
use Chargecloud\AvroMessengerSerializerBundle\Messenger\AvroMessageInterface;
use Chargecloud\AvroMessengerSerializerBundle\Messenger\HeaderProviderInterface;

final class CustomerHeaderProvider implements HeaderProviderInterface
{
    public function headersForMessage(AvroMessageInterface $message): array
    {
        return [
            'ce_specversion' => '1.0',
            'ce_id' => $message->eventId() ?? bin2hex(random_bytes(16)),
            'ce_type' => $message->eventType() ?? $message::class,
            'producer' => 'my-service',
        ];
    }
}
```

Reference the provider via configuration (`messages: ... header_provider: 'service.id'`) or in the serializer tag. Any service that implements `HeaderProviderInterface` is autoconfigured with the required tag, so a standard `autoconfigure: true` service definition is sufficient. Headers returned override or extend the defaults produced by the bundle serializer.

## Schema Management

Place your Avro schemas in any configured `schema_dirs`. Example layout:

```
config/
└── avro/
    ├── customer-key.avsc
    └── customer-value.avsc
```

Each `.avsc` schema is parsed and registered with the Confluent Schema Registry when the serializer encodes a message. Ensure the subjects in your tag configuration match the expected Schema Registry subjects or leave `value_subject` empty to allow tombstones.

### Subject naming conventions

`SchemaLoader` derives candidate subject names in the following order and keeps all valid options:

1. Base filename without the `.avsc` suffix (e.g. `key`, `value`).
2. Explicit `"subject"` declared inside the schema JSON.
3. `<namespace>.<folder>-<basename>` when the file lives inside nested directories.
4. `<namespace>.<name>` and `<name>` whenever both fields exist.

You can structure your schemas so the folder name encodes the logical subject stem. For example:

| Folder path           | Filename     | `namespace`          | `name`               | Derived subjects (in order)                                                                                     |
|-----------------------|--------------|----------------------|----------------------|-----------------------------------------------------------------------------------------------------------------|
| `…/position-updated/` | `key.avsc`   | `ocpi.queue.session` | `PositionUpdatedKey` | `key`, `ocpi.queue.session.position-updated-key`, `ocpi.queue.session.PositionUpdatedKey`, `PositionUpdatedKey` |
| `…/position-updated/` | `value.avsc` | `ocpi.queue.session` | `PositionUpdated`    | `value`, `ocpi.queue.session.position-updated-value`, `ocpi.queue.session.PositionUpdated`, `PositionUpdated`   |

The Confluent Schema Registry will receive subjects such as `ocpi.queue.session.position-updated-key` and `ocpi.queue.session.position-updated-value` when using the layout shown above.

### Schema references

When one schema depends on another (for example a nullable union that embeds a shared record), add a top-level `references` array to declare the subject names that must be resolved first:

```json
{
  "type": "record",
  "namespace": "ocpi",
  "name": "ChargingLocation",
  "references": [
    "ocpi.geo-location"
  ],
  "fields": [
    {
      "name": "coordinates",
      "type": ["null", "ocpi.GeoLocation"],
      "default": null
    }
  ]
}
```

The bundle uses these hints to load schemas in dependency order so cross-file references work regardless of discovery order. During message encoding the bundle ensures referenced subjects are registered first, then registers the dependent schema with proper [`AvroReference`](https://docs.confluent.io/platform/current/schema-registry/fundamentals/serdes-develop/index.html#schema-references) metadata so the relationship is visible in the Confluent Schema Registry.
