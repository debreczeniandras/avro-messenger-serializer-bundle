<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Messenger;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class AvroMessengerSerializer implements SerializerInterface
{
    private const HEADER_TYPE = 'type';
    private const HEADER_KEY_SUBJECT = 'x-chargecloud-avro-key-subject';
    private const HEADER_VALUE_SUBJECT = 'x-chargecloud-avro-value-subject';
    private const HEADER_CLASS = 'x-chargecloud-avro-class';
    private const HEADER_KEY_PAYLOAD = 'x-chargecloud-avro-key';
    private const HEADER_TOMBSTONE = 'x-chargecloud-avro-tombstone';

    public function __construct(
        private readonly MessageMetadataRegistry $metadataRegistry,
        private readonly RecordEncoder $recordEncoder,
        private readonly ?ContainerInterface $headerProviderLocator = null,
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    /**
     * @return array{body: string, headers: array<string, mixed>}
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        if (!$message instanceof AvroMessageInterface) {
            throw new \LogicException(\sprintf('Message of class "%s" must implement %s.', $message::class, AvroMessageInterface::class));
        }

        $metadata = $this->metadataRegistry->get($message::class);

        if (null === $metadata) {
            throw new \LogicException(\sprintf('No Avro metadata registered for message "%s".', $message::class));
        }

        $headers = [
            self::HEADER_CLASS => $metadata->className(),
            self::HEADER_TYPE => $metadata->className(),
        ];

        $encodedKey = null;
        $keySubject = $metadata->keySubject();
        $keyPayload = $message->avroKeyPayload();

        if (null !== $keySubject && null !== $keyPayload) {
            $encodedKey = $this->recordEncoder->encode($keySubject, $keyPayload);
            $headers[self::HEADER_KEY_SUBJECT] = $keySubject;
            $headers[self::HEADER_KEY_PAYLOAD] = base64_encode($encodedKey);
        }

        $valueSubject = $metadata->valueSubject();
        $valuePayload = $message->avroValuePayload();
        $body = '';
        $isTombstone = false;

        if (null === $valueSubject) {
            $isTombstone = true;
        } elseif (null === $valuePayload) {
            $isTombstone = true;
        } else {
            $body = $this->recordEncoder->encode($valueSubject, $valuePayload);
            $headers[self::HEADER_VALUE_SUBJECT] = $valueSubject;
        }

        if ($isTombstone) {
            $headers[self::HEADER_TOMBSTONE] = '1';
        }

        $headers = $this->mergeHeadersWithProvider($headers, $metadata, $message);

        /** @var array<string, mixed> $headers */
        return [
            'body' => $body,
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, mixed> $encodedEnvelope
     */
    public function decode(array $encodedEnvelope): Envelope
    {
        $headers = $encodedEnvelope['headers'] ?? [];

        if (!\is_array($headers)) {
            throw new MessageDecodingFailedException('Encoded headers should be an array.');
        }

        $className = $headers[self::HEADER_CLASS] ?? $headers[self::HEADER_TYPE] ?? null;

        if (!\is_string($className) || '' === $className) {
            throw new MessageDecodingFailedException('Missing message class information in headers.');
        }

        if (!is_subclass_of($className, AvroMessageInterface::class) && AvroMessageInterface::class !== $className) {
            throw new MessageDecodingFailedException(\sprintf('Message class "%s" must implement %s.', $className, AvroMessageInterface::class));
        }

        $metadata = $this->metadataRegistry->get($className);

        if (null === $metadata) {
            throw new MessageDecodingFailedException(\sprintf('No Avro metadata registered for message "%s".', $className));
        }

        $binaryKey = null;
        if (isset($headers[self::HEADER_KEY_PAYLOAD]) && \is_string($headers[self::HEADER_KEY_PAYLOAD])) {
            $binaryKey = base64_decode($headers[self::HEADER_KEY_PAYLOAD], true);

            if (false === $binaryKey) {
                throw new MessageDecodingFailedException('Failed to decode base64 encoded key payload.');
            }
        }

        $keyPayload = null;
        if (null !== $binaryKey && null !== $metadata->keySubject()) {
            $keyPayload = $this->recordEncoder->decode($metadata->keySubject(), $binaryKey);
        }

        $isTombstone = isset($headers[self::HEADER_TOMBSTONE]) && '1' === $headers[self::HEADER_TOMBSTONE];
        $valuePayload = null;
        $body = $encodedEnvelope['body'] ?? '';

        if (!$isTombstone) {
            if (!\is_string($body)) {
                throw new MessageDecodingFailedException('Encoded body must be a string.');
            }

            $valueSubject = $metadata->valueSubject();

            if (null === $valueSubject) {
                $isTombstone = true;
            } elseif ('' === $body) {
                $isTombstone = true;
            } else {
                $valuePayload = $this->recordEncoder->decode($valueSubject, $body);
            }
        }

        /** @var AvroMessageInterface $className */
        $message = $className::fromAvroPayload($keyPayload, $valuePayload);

        return new Envelope($message);
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    private function mergeHeadersWithProvider(array $headers, MessageMetadata $metadata, AvroMessageInterface $message): array
    {
        $providerId = $metadata->headerProviderServiceId();

        if (null === $providerId) {
            return $headers;
        }

        $provider = $this->locateHeaderProvider($providerId);

        $providedHeaders = $provider->headersForMessage($message);

        foreach ($providedHeaders as $name => $value) {
            if (!\is_string($name) || '' === $name) {
                continue;
            }

            if (!\is_scalar($value)) {
                continue;
            }

            $headers[$name] = (string) $value;
        }

        return $headers;
    }

    private function locateHeaderProvider(string $providerId): HeaderProviderInterface
    {
        $locators = array_filter([
            $this->headerProviderLocator,
            $this->container,
        ]);

        foreach ($locators as $locator) {
            if (!$locator->has($providerId)) {
                continue;
            }

            try {
                $provider = $locator->get($providerId);
            } catch (NotFoundExceptionInterface|ContainerExceptionInterface $exception) {
                throw new \LogicException(\sprintf('Failed to resolve header provider "%s".', $providerId), 0, $exception);
            }

            if (!$provider instanceof HeaderProviderInterface) {
                throw new \LogicException(\sprintf('Header provider "%s" must implement %s.', $providerId, HeaderProviderInterface::class));
            }

            return $provider;
        }

        throw new \LogicException(\sprintf('Header provider "%s" could not be located.', $providerId));
    }
}
