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

        $schemaEntries = [];
        $subjectIndex = [];

        foreach ($finder as $file) {
            $contents = $file->getContents();

            if ('' === $contents) {
                continue;
            }

            $decoded = $this->decodeSchemaJson($contents, (string) $file);
            $references = $this->extractReferences($decoded, (string) $file);
            unset($decoded['references']);

            $subjects = $this->deriveSubjects($file, $decoded);
            $subjects = array_values(array_unique(array_filter(
                $subjects ?? [],
                static fn ($subject): bool => \is_string($subject) && '' !== $subject
            )));

            $schemaEntries[] = [
                'file' => $file,
                'definition' => $decoded,
                'references' => $references,
                'subjects' => $subjects,
            ];

            $entryIndex = \count($schemaEntries) - 1;

            foreach ($subjects as $subject) {
                $subjectIndex[$subject] = $entryIndex;
            }
        }

        if ([] === $schemaEntries) {
            return $schemas;
        }

        $namedSchemata = new \AvroNamedSchemata();
        $loading = [];
        $loaded = [];

        $loadEntry = function (int $index) use (&$loadEntry, &$schemaEntries, &$schemas, &$subjectIndex, &$namedSchemata, &$loading, &$loaded): void {
            if (isset($loaded[$index])) {
                return;
            }

            if (isset($loading[$index])) {
                $path = $schemaEntries[$index]['file']->getRealPath() ?: (string) $schemaEntries[$index]['file'];
                throw new \RuntimeException(\sprintf('Circular schema reference detected while loading "%s".', $path));
            }

            $loading[$index] = true;
            $entry = $schemaEntries[$index];

            foreach ($entry['references'] as $referenceSubject) {
                if (!isset($subjectIndex[$referenceSubject])) {
                    $path = $entry['file']->getRealPath() ?: (string) $entry['file'];
                    throw new \RuntimeException(\sprintf('Schema "%s" references unknown subject "%s".', $path, $referenceSubject));
                }

                $loadEntry($subjectIndex[$referenceSubject]);
            }

            try {
                $schema = \AvroSchema::real_parse($entry['definition'], null, $namedSchemata);
            } catch (\AvroSchemaParseException $exception) {
                $path = $entry['file']->getRealPath() ?: (string) $entry['file'];
                throw new \RuntimeException(\sprintf('Failed to parse Avro schema "%s": %s', $path, $exception->getMessage()), 0, $exception);
            } catch (\Throwable $exception) {
                $path = $entry['file']->getRealPath() ?: (string) $entry['file'];
                throw new \RuntimeException(\sprintf('Failed to parse Avro schema "%s": %s', $path, $exception->getMessage()), 0, $exception);
            }

            foreach ($entry['subjects'] as $subject) {
                $schemas[$subject] = $schema;
            }

            $loaded[$index] = true;
            unset($loading[$index]);
        };

        foreach (\array_keys($schemaEntries) as $index) {
            $loadEntry((int) $index);
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
     * @return string[]
     */
    private function extractReferences(array $decoded, string $filePath): array
    {
        $references = $decoded['references'] ?? [];

        if (null === $references) {
            return [];
        }

        if (!\is_array($references)) {
            throw new \RuntimeException(\sprintf('Invalid "references" declaration in Avro schema "%s": expected an array of subject names.', $filePath));
        }

        $normalised = array_values(array_unique(array_filter(
            $references,
            static fn ($subject): bool => \is_string($subject) && '' !== $subject
        )));

        if (\count($normalised) !== \count($references)) {
            throw new \RuntimeException(\sprintf('Invalid "references" declaration in Avro schema "%s": all subjects must be non-empty strings.', $filePath));
        }

        return $normalised;
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
