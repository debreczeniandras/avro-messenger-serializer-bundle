<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle\Schema;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

final class SchemaLoader
{
    /** @var array<string, string[]> */
    private array $referencesBySubject = [];

    /** @var array<string, string> */
    private array $fullNamesBySubject = [];

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
        $this->referencesBySubject = [];
        $this->fullNamesBySubject = [];

        $directories = $this->filterExistingDirectories($this->directories);

        if ([] === $directories) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($directories)->name('*.avsc');

        $schemaEntries = $this->collectSchemaEntries($finder);

        if ([] === $schemaEntries) {
            return [];
        }

        $subjectIndex = $this->buildSubjectIndex($schemaEntries);
        $loadOrder = $this->resolveLoadOrder($schemaEntries, $subjectIndex);
        $namedSchemata = new \AvroNamedSchemata();
        $schemas = [];

        foreach ($loadOrder as $index) {
            $entry = $schemaEntries[$index];

            $schema = $this->resolveOrParseSchema(
                $entry['definition'],
                $entry['file'],
                $entry['full_name'],
                $namedSchemata
            );

            foreach ($entry['subjects'] as $subject) {
                $schemas[$subject] = $schema;
                $this->referencesBySubject[$subject] = $entry['references'];

                if (null !== $entry['full_name']) {
                    $this->fullNamesBySubject[$subject] = $entry['full_name'];
                }
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
     * @return array<string, string[]>
     */
    public function references(): array
    {
        return $this->referencesBySubject;
    }

    /**
     * @return array<string, string>
     */
    public function fullNames(): array
    {
        return $this->fullNamesBySubject;
    }

    /**
     * Collect schema metadata for every discovered `.avsc` file.
     *
     * @return array<int, array{file: SplFileInfo, definition: array<string, mixed>, references: string[], subjects: string[], full_name: ?string}>
     */
    private function collectSchemaEntries(Finder $finder): array
    {
        $entries = [];

        foreach ($finder as $file) {
            $contents = $file->getContents();

            if ('' === $contents) {
                continue;
            }

            $decoded = $this->decodeSchemaJson($contents, (string) $file);
            $references = $this->extractReferences($decoded, (string) $file);
            unset($decoded['references']);

            $subjects = $this->deriveSubjects($file, $decoded);
            $fullName = $this->resolveFullName($decoded);

            if (null !== $fullName) {
                $subjects[] = $fullName;
            }

            $subjects = array_values(array_unique(array_filter(
                $subjects ?? [],
                static fn ($subject): bool => \is_string($subject) && '' !== $subject
            )));

            $entries[] = [
                'file' => $file,
                'definition' => $decoded,
                'references' => $references,
                'subjects' => $subjects,
                'full_name' => $fullName,
            ];
        }

        return $entries;
    }

    /**
     * Build a lookup of subject name to schema entry index.
     *
     * @param array<int, array{subjects: string[]}> $schemaEntries
     *
     * @return array<string, int>
     */
    private function buildSubjectIndex(array $schemaEntries): array
    {
        $index = [];

        foreach ($schemaEntries as $entryIndex => $entry) {
            foreach ($entry['subjects'] as $subject) {
                $index[$subject] = (int) $entryIndex;
            }
        }

        return $index;
    }

    /**
     * Determine a load order that honours declared references while detecting cycles.
     *
     * @param array<int, array{file: SplFileInfo, definition: array<string, mixed>, references: string[], subjects: string[], full_name: ?string}> $schemaEntries
     * @param array<string, int>                                                                                                                   $subjectIndex
     *
     * @return int[]
     */
    private function resolveLoadOrder(array $schemaEntries, array $subjectIndex): array
    {
        $order = [];
        $visited = [];
        $visiting = [];

        $visit = function (int $index) use (&$visit, $schemaEntries, $subjectIndex, &$order, &$visited, &$visiting): void {
            if (isset($visited[$index])) {
                return;
            }

            if (isset($visiting[$index])) {
                $path = $schemaEntries[$index]['file']->getRealPath() ?: (string) $schemaEntries[$index]['file'];

                throw new \RuntimeException(\sprintf('Circular schema reference detected while loading "%s".', $path));
            }

            $visiting[$index] = true;
            $entry = $schemaEntries[$index];

            foreach ($entry['references'] as $referenceSubject) {
                if (!isset($subjectIndex[$referenceSubject])) {
                    $path = $entry['file']->getRealPath() ?: (string) $entry['file'];

                    throw new \RuntimeException(\sprintf('Schema "%s" references unknown subject "%s".', $path, $referenceSubject));
                }

                $visit($subjectIndex[$referenceSubject]);
            }

            $visiting[$index] = false;
            $visited[$index] = true;
            $order[] = $index;
        };

        foreach (array_keys($schemaEntries) as $index) {
            $visit((int) $index);
        }

        return $order;
    }

    /**
     * Parse a schema definition while sharing named schema state.
     *
     * @param array<string, mixed> $definition
     */
    private function resolveOrParseSchema(array $definition, SplFileInfo $file, ?string $fullName, \AvroNamedSchemata &$namedSchemata): \AvroSchema
    {
        if (null !== $fullName && $namedSchemata->has_name($fullName)) {
            $existing = $namedSchemata->schema($fullName);

            if (null !== $existing) {
                return $existing;
            }
        }

        try {
            return \AvroSchema::real_parse($definition, null, $namedSchemata);
        } catch (\AvroSchemaParseException $exception) {
            if (null !== $fullName && $namedSchemata->has_name($fullName)) {
                $existing = $namedSchemata->schema($fullName);

                if (null !== $existing) {
                    return $existing;
                }
            }

            $path = $file->getRealPath() ?: (string) $file;

            throw new \RuntimeException(\sprintf('Failed to parse Avro schema "%s": %s', $path, $exception->getMessage()), 0, $exception);
        } catch (\Throwable $exception) {
            $path = $file->getRealPath() ?: (string) $file;

            throw new \RuntimeException(\sprintf('Failed to parse Avro schema "%s": %s', $path, $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * Resolve the fully qualified Avro name if available.
     *
     * @param array<string, mixed> $decoded
     */
    private function resolveFullName(array $decoded): ?string
    {
        $name = $decoded['name'] ?? null;

        if (!\is_string($name) || '' === $name) {
            return null;
        }

        $namespace = $decoded['namespace'] ?? null;

        try {
            $avroName = new \AvroName(
                $name,
                \is_string($namespace) && '' !== $namespace ? $namespace : null,
                null
            );
        } catch (\Throwable $exception) {
            return null;
        }

        return $avroName->fullname();
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
