<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Schema;

use Symfony\Component\Finder\Finder;

final class SchemaLoader
{
    /**
     * @param string[] $directories
     */
    public function __construct(
        private readonly array $directories,
    ) {
    }

    /**
     * @return array<string, \AvroSchema>
     */
    public function load(): array
    {
        $schemas = [];
        $directories = $this->filterExistingDirectories($this->directories);

        if ([] === $directories) {
            return $schemas;
        }

        $finder = new Finder();
        $finder->files()->in($directories)->name('*.avsc');

        foreach ($finder as $file) {
            $contents = $file->getContents();

            if ('' === $contents) {
                continue;
            }

            try {
                $schema = \AvroSchema::parse($contents);
            } catch (\Throwable $exception) {
                throw new \RuntimeException(\sprintf('Failed to parse Avro schema "%s": %s', $file->getRealPath(), $exception->getMessage()), 0, $exception);
            }

            $decoded = $this->decodeSchemaJson($contents, (string) $file);
            $subjects = $this->deriveSubjects($file->getBasename('.avsc'), $decoded);

            foreach (array_unique($subjects) as $subject) {
                if ('' === $subject) {
                    continue;
                }

                $schemas[$subject] = $schema;
            }
        }

        return $schemas;
    }

    /**
     * @param array<array-key, string> $directories
     *
     * @return string[]
     */
    private function filterExistingDirectories(array $directories): array
    {
        return array_values(array_filter(
            $directories,
            static fn (string $directory): bool => is_dir($directory)
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSchemaJson(string $contents, string $filePath): array
    {
        try {
            $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(\sprintf('Invalid JSON in Avro schema "%s": %s', $filePath, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('Unexpected JSON structure in Avro schema "%s"', $filePath));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @return string[]
     */
    private function deriveSubjects(string $basename, array $decoded): array
    {
        $subjects = [$basename];

        $subjectFromSchema = $decoded['subject'] ?? null;
        if (\is_string($subjectFromSchema) && '' !== $subjectFromSchema) {
            $subjects[] = $subjectFromSchema;
        }

        $name = $decoded['name'] ?? null;
        if (!\is_string($name) || '' === $name) {
            return $subjects;
        }

        $namespace = $decoded['namespace'] ?? null;
        if (\is_string($namespace) && '' !== $namespace) {
            $subjects[] = \sprintf('%s.%s', $namespace, $name);
        }

        $subjects[] = $name;

        return $subjects;
    }
}
