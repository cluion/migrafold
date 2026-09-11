<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Output\Exception\UnsafeOutputOperation;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use JsonException;

final class BaselineOutputPlanner
{
    /**
     * @param list<GeneratedMigration> $migrations
     * @param list<SourceMigration> $sources
     * @throws JsonException
     */
    public function plan(
        string $directory,
        SchemaSnapshot $snapshot,
        array $migrations,
        array $sources,
        ?BaselineManifestOwner $owner = null,
    ): BaselineOutputPlan {
        $directory = $this->normalizeDirectory($directory);
        $files = $this->migrationFiles($migrations);
        $sources = $this->normalizeSources($sources);

        $manifest = [
            'format_version' => 'migrafold-manifest-v1',
            'schema' => [
                'format_version' => $snapshot->formatVersion,
                'driver' => $snapshot->driver,
                'sha256' => $snapshot->fingerprint(),
            ],
            'sources' => array_map(
                static fn (SourceMigration $source): array => $source->toArray(),
                $sources,
            ),
            'outputs' => array_map(
                static fn (PlannedOutputFile $file): array => $file->manifestEntry(),
                $files,
            ),
        ];

        if ($owner !== null) {
            $manifest['owner'] = $owner->toArray();
        }

        $contents = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";

        return new BaselineOutputPlan($directory, $files, $contents);
    }

    /**
     * @param list<GeneratedMigration> $migrations
     * @return list<PlannedOutputFile>
     */
    private function migrationFiles(array $migrations): array
    {
        if ($migrations === []) {
            throw UnsafeOutputOperation::because('at least one generated migration is required.');
        }

        $files = [];
        $filenames = [];
        $tables = [];

        foreach ($migrations as $migration) {
            if (
                basename($migration->filename) !== $migration->filename
                || preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_create_[a-z0-9_]+_baseline\.php$/', $migration->filename) !== 1
            ) {
                throw UnsafeOutputOperation::because(
                    "generated migration filename [{$migration->filename}] is unsafe.",
                );
            }

            if (! str_starts_with($migration->contents, "<?php\n")) {
                throw UnsafeOutputOperation::because(
                    "generated migration [{$migration->filename}] does not contain a PHP file.",
                );
            }

            $filenameKey = strtolower($migration->filename);
            $tableKey = strtolower($migration->table);

            if (isset($filenames[$filenameKey])) {
                throw UnsafeOutputOperation::because(
                    "duplicate generated migration filename [{$migration->filename}].",
                );
            }

            if ($migration->table === '' || isset($tables[$tableKey])) {
                throw UnsafeOutputOperation::because(
                    "duplicate or empty generated table [{$migration->table}].",
                );
            }

            $filenames[$filenameKey] = true;
            $tables[$tableKey] = true;
            $files[] = new PlannedOutputFile(
                $migration->filename,
                $migration->contents,
                $migration->table,
            );
        }

        return $files;
    }

    /**
     * @param list<SourceMigration> $sources
     * @return list<SourceMigration>
     */
    private function normalizeSources(array $sources): array
    {
        usort(
            $sources,
            static fn (SourceMigration $left, SourceMigration $right): int => strcmp($left->path, $right->path),
        );
        $paths = [];

        foreach ($sources as $source) {
            $key = strtolower($source->path);

            if (isset($paths[$key])) {
                throw UnsafeOutputOperation::because("duplicate source migration [{$source->path}].");
            }

            $paths[$key] = true;
        }

        return $sources;
    }

    private function normalizeDirectory(string $directory): string
    {
        if (str_contains($directory, "\0")) {
            throw UnsafeOutputOperation::because('output directory contains a null byte.');
        }

        $normalized = rtrim($directory, '/\\');
        $portable = str_replace('\\', '/', $normalized);
        $absolute = str_starts_with($portable, '/') || preg_match('/^[a-zA-Z]:\//', $portable) === 1;

        if (! $absolute || $normalized === '' || $portable === '/' || preg_match('/^[a-zA-Z]:$/', $portable) === 1) {
            throw UnsafeOutputOperation::because(
                "output directory [{$directory}] must be an absolute non-root path.",
            );
        }

        $segments = explode('/', $portable);

        if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw UnsafeOutputOperation::because(
                "output directory [{$directory}] must not contain traversal segments.",
            );
        }

        return $normalized;
    }
}
