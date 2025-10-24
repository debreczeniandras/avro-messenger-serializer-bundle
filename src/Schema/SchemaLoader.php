<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Schema;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

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
     * Build a map of subject name to Avro schema by scanning configured directories.
     *
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
            $subjects = $this->deriveSubjects($file, $decoded);

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
     * Keep only directories that exist at runtime to avoid filesystem errors.
     *
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
     * Decode schema JSON into an associative array while surfacing parsing issues.
     *
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
     * Derive all viable subject names for a schema, merging explicit hints with conventions.
     *
     * @param array<string, mixed> $decoded
     *
     * @return string[]
     */
    private function deriveSubjects(SplFileInfo $file, array $decoded): array
    {
        $basename = $file->getBasename('.avsc');
        $subjectFromSchema = $decoded['subject'] ?? null;
        if (\is_string($subjectFromSchema) && '' !== $subjectFromSchema) {
            $subjects[] = $subjectFromSchema;
        }

        $namespace = $decoded['namespace'] ?? null;
        $directorySubject = $this->subjectFromDirectory($file, \is_string($namespace) ? $namespace : null, $basename);
        if (null !== $directorySubject && '' !== $directorySubject) {
            $subjects[] = $directorySubject; // folder-based convention (e.g. namespace.folder-basename)
        }

        $name = $decoded['name'] ?? null;
        if (!\is_string($name) || '' === $name) {
            return $subjects;
        }

        if (\is_string($namespace) && '' !== $namespace) {
            $subjects[] = \sprintf('%s.%s', $namespace, $name);
        }

        return $subjects;
    }

    /**
     * Create a subject name from the schema's relative directory and optional namespace.
     */
    private function subjectFromDirectory(SplFileInfo $file, ?string $namespace, string $basename): ?string
    {
        $relativePath = $file->getRelativePath();

        if (null === $relativePath || '' === $relativePath) {
            return null;
        }

        $pathSegments = preg_split('~[\\\\/]+~', $relativePath, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $pathSegments || [] === $pathSegments) {
            return null;
        }

        $segment = (string) array_pop($pathSegments); // last directory contains the logical subject name
        if ('' === $segment) {
            return null;
        }

        if (null !== $namespace && '' !== $namespace) {
            return \sprintf('%s.%s-%s', $namespace, $segment, $basename);
        }

        return \sprintf('%s-%s', $segment, $basename);
    }
}
