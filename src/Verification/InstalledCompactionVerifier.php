<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Verification;

use Cluion\Migrafold\Discovery\DiscoveredMigration;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Discovery\MigrationDiscoverer;
use Cluion\Migrafold\Discovery\MigrationOwner;
use Cluion\Migrafold\Discovery\MigrationSourceAdapter;
use Cluion\Migrafold\Planning\SchemaInspectorResolver;
use Cluion\Migrafold\Verification\Exception\ManifestVerificationFailed;
use Illuminate\Database\Connection;
use JsonException;

final readonly class InstalledCompactionVerifier
{
    public function __construct(
        private MigrationDiscoverer $discoverer = new MigrationDiscoverer(),
        private CompactionManifestReader $reader = new CompactionManifestReader(),
        private SchemaInspectorResolver $inspectors = new SchemaInspectorResolver(),
    ) {}

    /** @param list<MigrationSourceAdapter> $adapters */
    public function verify(
        string $projectRoot,
        Connection $connection,
        array $adapters,
        string $migrationTable = 'migrations',
    ): InstalledCompactionVerification {
        $root = realpath($projectRoot);

        if ($root === false || ! is_dir($root)) {
            throw ManifestVerificationFailed::because(
                "project root [{$projectRoot}] is not a readable directory.",
            );
        }

        $this->assertMigrationTable($connection, $migrationTable);
        $catalog = $this->discoverer->discover($adapters);
        $manifests = $this->manifests($root, $catalog);
        $reference = $manifests[0];
        $this->assertSharedAudit($manifests, $reference);
        $currentMigrations = $this->currentMigrations($catalog);
        $baselineNames = $this->verifyOutputs($root, $manifests, $currentMigrations);
        $this->verifyMigrationScope($root, $catalog, $manifests, $reference, $currentMigrations);
        $snapshot = $this->inspectors->resolve($connection)->inspect(
            $connection,
            array_values(array_unique(['migrations', $migrationTable])),
        );

        if ($snapshot->driver !== $reference->driver
            || $snapshot->fingerprint() !== $reference->schemaFingerprint) {
            throw ManifestVerificationFailed::because(
                'current database schema does not match the verified manifest fingerprint.',
            );
        }

        [$batch, $preservedRecorded, $preservedPending] = $this->verifyRecords(
            $connection,
            $migrationTable,
            $baselineNames,
            array_map(
                static fn (ManifestMigration $migration): string => $migration->name,
                $reference->compacted,
            ),
            array_map(
                static fn (ManifestMigration $migration): string => $migration->name,
                $reference->preserved,
            ),
        );
        $trackedNames = array_fill_keys(array_map('strtolower', [
            ...$baselineNames,
            ...array_map(
                static fn (ManifestMigration $migration): string => $migration->name,
                $reference->preserved,
            ),
        ]), true);
        $untracked = [];

        foreach ($catalog->migrations as $migration) {
            if (! isset($trackedNames[strtolower($migration->name)])) {
                $untracked[] = $migration->name;
            }
        }

        sort($untracked, SORT_STRING);

        return new InstalledCompactionVerification(
            driver: $snapshot->driver,
            schemaFingerprint: $snapshot->fingerprint(),
            migrationTable: $migrationTable,
            baselineBatch: $batch,
            manifests: array_map(
                static fn (InstalledCompactionManifest $manifest): array => [
                    'owner' => $manifest->ownerId,
                    'path' => $manifest->path,
                ],
                $manifests,
            ),
            compacted: array_map(
                static fn (ManifestMigration $migration): string => $migration->name,
                $reference->compacted,
            ),
            preserved: array_map(
                static fn (ManifestMigration $migration): string => $migration->name,
                $reference->preserved,
            ),
            baselines: $baselineNames,
            preservedRecorded: $preservedRecorded,
            preservedPending: $preservedPending,
            untrackedMigrations: $untracked,
        );
    }

    /** @return list<InstalledCompactionManifest> */
    private function manifests(string $root, MigrationCatalog $catalog): array
    {
        $manifests = [];
        $directories = [];

        foreach ($catalog->owners as $owner) {
            $manifest = $this->reader->read($root, $owner);

            if ($manifest === null) {
                continue;
            }

            $directory = strtolower($this->normalizePath($manifest->directory));

            if (isset($directories[$directory])) {
                throw ManifestVerificationFailed::because(
                    "manifest directory [{$manifest->directory}] is shared by multiple owners.",
                );
            }

            $directories[$directory] = true;
            $manifests[] = $manifest;
        }

        if ($manifests === []) {
            throw ManifestVerificationFailed::because('no v2 compaction manifest was found for an active owner.');
        }

        usort(
            $manifests,
            static fn (InstalledCompactionManifest $left, InstalledCompactionManifest $right): int => strcmp(
                $left->ownerId,
                $right->ownerId,
            ),
        );

        return $manifests;
    }

    /**
     * @param list<InstalledCompactionManifest> $manifests
     * @throws JsonException
     */
    private function assertSharedAudit(
        array $manifests,
        InstalledCompactionManifest $reference,
    ): void {
        $fingerprint = $reference->auditFingerprint();

        foreach ($manifests as $manifest) {
            if ($manifest->auditFingerprint() !== $fingerprint) {
                throw ManifestVerificationFailed::because(
                    "manifest for [{$manifest->ownerId}] does not share the same global audit scope.",
                );
            }
        }
    }

    /** @return array<string, DiscoveredMigration> */
    private function currentMigrations(MigrationCatalog $catalog): array
    {
        $migrations = [];

        foreach ($catalog->migrations as $migration) {
            $path = strtolower($this->normalizePath($migration->absolutePath));
            $migrations[$path] = $migration;
        }

        return $migrations;
    }

    /**
     * @param list<InstalledCompactionManifest> $manifests
     * @param array<string, DiscoveredMigration> $current
     * @return list<string>
     */
    private function verifyOutputs(
        string $root,
        array $manifests,
        array $current,
    ): array {
        $names = [];
        $paths = [];
        $tables = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->outputs as $output) {
                $path = $manifest->directory.DIRECTORY_SEPARATOR.$output->filename;
                $resolved = $this->verifiedFile($root, $path, $output->sha256, 'baseline output');
                $pathKey = strtolower($this->normalizePath($resolved));
                $nameKey = strtolower($output->name);
                $tableKey = strtolower($output->table);

                if (isset($paths[$pathKey]) || isset($names[$nameKey]) || isset($tables[$tableKey])) {
                    throw ManifestVerificationFailed::because(
                        "baseline output [{$output->filename}] is duplicated across manifests.",
                    );
                }

                $discovered = $current[$pathKey] ?? null;

                if ($discovered === null
                    || $discovered->name !== $output->name
                    || $discovered->ownerId !== $manifest->ownerId
                    || $discovered->source->sha256 !== $output->sha256) {
                    throw ManifestVerificationFailed::because(
                        "baseline output [{$output->filename}] does not match current migration discovery.",
                    );
                }

                $paths[$pathKey] = true;
                $names[$nameKey] = $output->name;
                $tables[$tableKey] = true;
            }
        }

        if (count($names) !== $manifests[0]->baselineMigrations) {
            throw ManifestVerificationFailed::because(
                'published baseline count does not match the verified manifest scope.',
            );
        }

        $baselineNames = array_values($names);
        sort($baselineNames, SORT_STRING);

        return $baselineNames;
    }

    /**
     * @param list<InstalledCompactionManifest> $manifests
     * @param array<string, DiscoveredMigration> $current
     */
    private function verifyMigrationScope(
        string $root,
        MigrationCatalog $catalog,
        array $manifests,
        InstalledCompactionManifest $reference,
        array $current,
    ): void {
        $owners = array_fill_keys(array_map(
            static fn (MigrationOwner $owner): string => strtolower($owner->id),
            $catalog->owners,
        ), true);
        $manifestOwners = array_fill_keys(array_map(
            static fn (InstalledCompactionManifest $manifest): string => strtolower($manifest->ownerId),
            $manifests,
        ), true);

        foreach ($reference->compacted as $migration) {
            if (! isset($owners[strtolower($migration->ownerId)])) {
                throw ManifestVerificationFailed::because(
                    "compacted migration [{$migration->name}] belongs to an inactive or unknown owner.",
                );
            }

            if (! isset($manifestOwners[strtolower($migration->ownerId)])) {
                throw ManifestVerificationFailed::because(
                    "compacted migration [{$migration->name}] has no owner manifest.",
                );
            }

            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $migration->path);

            if (file_exists($path) || is_link($path)) {
                throw ManifestVerificationFailed::because(
                    "compacted source [{$migration->path}] is still active.",
                );
            }
        }

        foreach ($reference->preserved as $migration) {
            if (! isset($owners[strtolower($migration->ownerId)])) {
                throw ManifestVerificationFailed::because(
                    "preserved migration [{$migration->name}] belongs to an inactive or unknown owner.",
                );
            }

            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $migration->path);
            $resolved = $this->verifiedFile($root, $path, $migration->sha256, 'preserved migration');
            $pathKey = strtolower($this->normalizePath($resolved));
            $discovered = $current[$pathKey] ?? null;

            if ($discovered === null
                || $discovered->name !== $migration->name
                || $discovered->ownerId !== $migration->ownerId
                || $discovered->source->sha256 !== $migration->sha256) {
                throw ManifestVerificationFailed::because(
                    "preserved migration [{$migration->name}] does not match current discovery.",
                );
            }
        }
    }

    /**
     * @param list<string> $baselines
     * @param list<string> $compacted
     * @param list<string> $preserved
     * @return array{0: int, 1: list<string>, 2: list<string>}
     */
    private function verifyRecords(
        Connection $connection,
        string $table,
        array $baselines,
        array $compacted,
        array $preserved,
    ): array {
        $expected = [];

        foreach ([...$baselines, ...$compacted, ...$preserved] as $name) {
            $key = strtolower($name);

            if (isset($expected[$key])) {
                throw ManifestVerificationFailed::because(
                    "migration record scope [{$name}] overlaps [{$expected[$key]}].",
                );
            }

            $expected[$key] = $name;
        }

        $counts = array_fill_keys(array_values($expected), 0);
        $batches = [];
        $rows = $connection->table($table)->orderBy('id')->get(['migration', 'batch']);

        foreach ($rows as $row) {
            if (! is_string($row->migration) || ! is_int($row->batch)) {
                throw ManifestVerificationFailed::because('migration repository contains an invalid record shape.');
            }

            $name = $expected[strtolower($row->migration)] ?? null;

            if ($name === null) {
                continue;
            }

            if ($row->migration !== $name) {
                throw ManifestVerificationFailed::because(
                    "migration repository contains case-conflicting record [{$row->migration}].",
                );
            }

            $counts[$name]++;

            if (in_array($name, $baselines, true)) {
                $batches[$row->batch] = true;
            }
        }

        foreach ($baselines as $name) {
            if ($counts[$name] !== 1) {
                throw ManifestVerificationFailed::because(
                    "baseline migration record [{$name}] is missing or duplicated.",
                );
            }
        }

        foreach ($compacted as $name) {
            if ($counts[$name] !== 0) {
                throw ManifestVerificationFailed::because(
                    "retired migration record [{$name}] is still present.",
                );
            }
        }

        $recorded = [];
        $pending = [];

        foreach ($preserved as $name) {
            if ($counts[$name] > 1) {
                throw ManifestVerificationFailed::because(
                    "preserved migration record [{$name}] is duplicated.",
                );
            }

            if ($counts[$name] === 1) {
                $recorded[] = $name;
            } else {
                $pending[] = $name;
            }
        }

        if (count($batches) !== 1) {
            throw ManifestVerificationFailed::because(
                'baseline migration records do not share one migration batch.',
            );
        }

        sort($recorded, SORT_STRING);
        sort($pending, SORT_STRING);

        return [(int) array_key_first($batches), $recorded, $pending];
    }

    private function assertMigrationTable(Connection $connection, string $table): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $table) !== 1
            || ! $connection->getSchemaBuilder()->hasTable($table)) {
            throw ManifestVerificationFailed::because(
                "migration repository table [{$table}] is invalid or missing.",
            );
        }
    }

    private function verifiedFile(string $root, string $path, string $fingerprint, string $label): string
    {
        if (is_link($path)) {
            throw ManifestVerificationFailed::because("{$label} [{$path}] must not be a symbolic link.");
        }

        $resolved = realpath($path);

        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved) || ! $this->within($root, $resolved)) {
            throw ManifestVerificationFailed::because("{$label} [{$path}] is missing or outside the project root.");
        }

        $actual = hash_file('sha256', $resolved);

        if (! is_string($actual) || ! hash_equals($fingerprint, $actual)) {
            throw ManifestVerificationFailed::because("{$label} [{$path}] does not match its manifest fingerprint.");
        }

        return $resolved;
    }

    private function within(string $root, string $path): bool
    {
        return str_starts_with(
            str_replace('\\', '/', $path).'/',
            rtrim(str_replace('\\', '/', $root), '/').'/',
        );
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/\\'));
    }
}
