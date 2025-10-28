<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Schema;

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

        $schemaFiles = [];

        foreach ($finder as $file) {
            $contents = $file->getContents();

            if ('' === $contents) {
                continue;
            }

            $decoded = $this->decodeSchemaJson($contents, (string) $file);
            $subjects = $this->deriveSubjects($file, $decoded);
            $subjects = \is_array($subjects) ? $subjects : [];

            $schemaFiles[] = [
                'file' => $file,
                'decoded' => $decoded,
                'subjects' => array_values(array_unique(array_filter(
                    $subjects,
                    static fn ($subject): bool => \is_string($subject) && '' !== $subject
                ))),
            ];
        }

        if ([] === $schemaFiles) {
            return $schemas;
        }

        $pending = $schemaFiles;
        $parseErrors = [];
        $namedSchemata = new \AvroNamedSchemata();

        // Parse schemas with a shared registry so inter-file references resolve regardless of discovery order.
        while ([] !== $pending) {
            $parsedInPass = false;

            foreach ($pending as $index => $schemaFile) {
                try {
                    $schema = \AvroSchema::real_parse($schemaFile['decoded'], null, $namedSchemata);
                } catch (\AvroSchemaParseException $exception) {
                    $parseErrors[$index] = $exception;
                    continue;
                } catch (\Throwable $exception) {
                    $path = $schemaFile['file']->getRealPath() ?: (string) $schemaFile['file'];

                    throw new \RuntimeException(\sprintf('Failed to parse Avro schema "%s": %s', $path, $exception->getMessage()), 0, $exception);
                }

                foreach ($schemaFile['subjects'] as $subject) {
                    $schemas[$subject] = $schema;
                }

                unset($pending[$index], $parseErrors[$index]);
                $parsedInPass = true;
            }

            if ($parsedInPass) {
                continue;
            }

            $firstKey = array_key_first($pending);
            if (null === $firstKey) {
                break;
            }

            $schemaFile = $pending[$firstKey];
            $path = $schemaFile['file']->getRealPath() ?: (string) $schemaFile['file'];
            $exception = $parseErrors[$firstKey] ?? null;
            $message = null !== $exception ? $exception->getMessage() : 'Unknown parsing error';

            throw new \RuntimeException(\sprintf('Failed to parse Avro schema "%s": %s', $path, $message), 0, $exception);
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
