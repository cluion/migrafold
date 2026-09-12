<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Activation;

use Cluion\Migrafold\Activation\Exception\MigrationRecordActivationFailed;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Output\OutputMode;
use Illuminate\Database\Connection;
use Throwable;

final readonly class MigrationRecordActivator
{
    public function __construct(
        private MigrationRecordTransaction $transaction = new DatabaseMigrationRecordTransaction(),
    ) {}

    public function execute(
        MigrationRecordActivationPlan $plan,
        Connection $connection,
        string $table = 'migrations',
        OutputMode $mode = OutputMode::DryRun,
    ): MigrationRecordActivationResult {
        $this->preflight($plan, $mode === OutputMode::Write);
        $this->assertTable($connection, $table);

        if ($mode === OutputMode::DryRun) {
            $records = $this->records($connection, $table);
            $state = $this->state($records, $plan);
            $batch = $state === 'activated'
                ? $this->batchFor($records, $plan->baselineNames())
                : null;

            return $this->result($plan, $mode, $batch, $state === 'activated');
        }

        try {
            return $this->transaction->run(
                $connection,
                $connection->getName().':'.$connection->getDatabaseName().':'.$table,
                fn (): MigrationRecordActivationResult => $this->activate($plan, $connection, $table),
            );
        } catch (MigrationRecordActivationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw MigrationRecordActivationFailed::because(
                'database transaction failed; migration records were not activated.',
                $exception,
            );
        }
    }

    private function activate(
        MigrationRecordActivationPlan $plan,
        Connection $connection,
        string $table,
    ): MigrationRecordActivationResult {
        $records = $this->records($connection, $table);
        $state = $this->state($records, $plan);

        if ($state === 'activated') {
            $batch = $this->batchFor($records, $plan->baselineNames());

            return $this->result($plan, OutputMode::Write, $batch, true);
        }

        $retired = array_fill_keys($plan->retiredNames(), true);
        $retiredIds = [];
        $unrelated = [];
        $maximumBatch = 0;

        foreach ($records as $record) {
            $maximumBatch = max($maximumBatch, $record['batch']);

            if (isset($retired[$record['migration']])) {
                $retiredIds[] = $record['id'];
            } else {
                $unrelated[] = $record;
            }
        }

        $deleted = $connection->table($table)->whereIn('id', $retiredIds)->delete();

        if ($deleted !== count($retiredIds)) {
            throw MigrationRecordActivationFailed::because(
                'migration repository changed while retiring old records.',
            );
        }

        $batch = $maximumBatch + 1;
        $insert = array_map(
            static fn (string $name): array => ['migration' => $name, 'batch' => $batch],
            $plan->baselineNames(),
        );

        if (! $connection->table($table)->insert($insert)) {
            throw MigrationRecordActivationFailed::because('baseline migration records could not be inserted.');
        }

        $after = $this->records($connection, $table);

        if ($this->state($after, $plan) !== 'activated' || $this->unrelated($after, $plan) !== $unrelated) {
            throw MigrationRecordActivationFailed::because(
                'post-write verification detected an unexpected migration repository change.',
            );
        }

        return $this->result($plan, OutputMode::Write, $batch, false);
    }

    private function preflight(MigrationRecordActivationPlan $plan, bool $requireDisposedSources): void
    {
        $root = realpath($plan->projectRoot);

        if (
            $root === false
            || $root !== $plan->projectRoot
            || $plan->retired === []
            || $plan->baselines === []
            || $plan->protectedFiles === []
        ) {
            throw MigrationRecordActivationFailed::because('activation plan has an invalid root or empty scope.');
        }

        $protected = [];

        foreach ($plan->protectedFiles as $file) {
            $directory = realpath($file->migrationDirectory);

            if (
                $directory === false
                || $directory !== $file->migrationDirectory
                || dirname($file->path) !== $directory
            ) {
                throw MigrationRecordActivationFailed::because(
                    "protected baseline [{$file->path}] has inconsistent owner path metadata.",
                );
            }

            $this->assertWithinRoot($root, $file->path, 'protected baseline');
            $this->assertFingerprint($file->path, $file->sha256, 'protected baseline');
            $pathKey = strtolower($this->normalizePath($file->path));

            if (isset($protected[$pathKey])) {
                throw MigrationRecordActivationFailed::because(
                    "protected baseline [{$file->path}] is duplicated.",
                );
            }

            $protected[$pathKey] = $file;
        }

        $names = [];

        foreach ($plan->baselines as $baseline) {
            $this->assertMigrationName($baseline->name);
            $key = strtolower($baseline->name);

            if (isset($names[$key])) {
                throw MigrationRecordActivationFailed::because(
                    "baseline migration [{$baseline->name}] has a duplicate record name.",
                );
            }

            $names[$key] = true;
            $pathKey = strtolower($this->normalizePath($baseline->file->path));
            $protectedFile = $protected[$pathKey] ?? null;

            if (
                $protectedFile === null
                || $protectedFile->ownerId !== $baseline->file->ownerId
                || $protectedFile->migrationDirectory !== $baseline->file->migrationDirectory
                || $protectedFile->sha256 !== $baseline->file->sha256
                || pathinfo($baseline->file->path, PATHINFO_FILENAME) !== $baseline->name
            ) {
                throw MigrationRecordActivationFailed::because(
                    "baseline migration [{$baseline->name}] does not match a protected output.",
                );
            }
        }

        foreach ($plan->retired as $retired) {
            $this->assertMigrationName($retired->name);
            $key = strtolower($retired->name);

            if (isset($names[$key])) {
                throw MigrationRecordActivationFailed::because(
                    "retired migration [{$retired->name}] overlaps another record name.",
                );
            }

            $names[$key] = true;
            $this->assertWithinRoot($root, $retired->source->source, 'retired source');
            $directory = realpath($retired->source->migrationDirectory);

            if (
                $directory === false
                || $directory !== $retired->source->migrationDirectory
                || dirname($retired->source->source) !== $directory
                || basename($retired->source->source) !== $retired->name.'.php'
                || basename($retired->source->relativeSource) !== $retired->name.'.php'
            ) {
                throw MigrationRecordActivationFailed::because(
                    "retired migration [{$retired->name}] has inconsistent source path metadata.",
                );
            }

            $destination = $retired->source->destination;

            if ($plan->disposition === SourceDispositionMode::Archive) {
                if ($destination === null || basename($destination) !== $retired->name.'.php') {
                    throw MigrationRecordActivationFailed::because(
                        "retired source [{$retired->source->relativeSource}] has no matching archive path.",
                    );
                }

                $this->assertWithinRoot($root, $destination, 'archived source');
            } elseif ($destination !== null) {
                throw MigrationRecordActivationFailed::because(
                    "deleted source [{$retired->source->relativeSource}] has an unexpected archive path.",
                );
            }

            if (! $requireDisposedSources) {
                continue;
            }

            if (file_exists($retired->source->source) || is_link($retired->source->source)) {
                throw MigrationRecordActivationFailed::because(
                    "retired source [{$retired->source->relativeSource}] is still active.",
                );
            }

            if ($plan->disposition === SourceDispositionMode::Archive) {
                $this->assertFingerprint($destination, $retired->source->sha256, 'archived source');
            }
        }
    }

    private function assertTable(Connection $connection, string $table): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $table) !== 1) {
            throw MigrationRecordActivationFailed::because("migration table [{$table}] is invalid.");
        }

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            throw MigrationRecordActivationFailed::because("migration table [{$table}] does not exist.");
        }
    }

    /**
     * @return list<array{id: int, migration: string, batch: int}>
     */
    private function records(Connection $connection, string $table): array
    {
        $rows = $connection->table($table)->orderBy('id')->get(['id', 'migration', 'batch']);
        $records = [];

        foreach ($rows as $row) {
            if (
                ! is_int($row->id)
                || ! is_string($row->migration)
                || ! is_int($row->batch)
            ) {
                throw MigrationRecordActivationFailed::because(
                    'migration repository contains an invalid record shape.',
                );
            }

            $records[] = [
                'id' => $row->id,
                'migration' => $row->migration,
                'batch' => $row->batch,
            ];
        }

        return $records;
    }

    /**
     * @param list<array{id: int, migration: string, batch: int}> $records
     */
    private function state(array $records, MigrationRecordActivationPlan $plan): string
    {
        $retired = array_fill_keys($plan->retiredNames(), 0);
        $baselines = array_fill_keys($plan->baselineNames(), 0);
        $scope = [];

        foreach ([...array_keys($retired), ...array_keys($baselines)] as $name) {
            $scope[strtolower($name)] = $name;
        }

        foreach ($records as $record) {
            $expected = $scope[strtolower($record['migration'])] ?? null;

            if ($expected !== null && $record['migration'] !== $expected) {
                throw MigrationRecordActivationFailed::because(
                    "migration repository contains case-conflicting record [{$record['migration']}].",
                );
            }

            if (isset($retired[$record['migration']])) {
                $retired[$record['migration']]++;
            }

            if (isset($baselines[$record['migration']])) {
                $baselines[$record['migration']]++;
            }
        }

        $allRetiredPresent = $this->allCounts($retired, 1);
        $noRetiredPresent = $this->allCounts($retired, 0);
        $allBaselinesPresent = $this->allCounts($baselines, 1);
        $noBaselinesPresent = $this->allCounts($baselines, 0);

        if ($allRetiredPresent && $noBaselinesPresent) {
            return 'ready';
        }

        if ($noRetiredPresent && $allBaselinesPresent) {
            return 'activated';
        }

        throw MigrationRecordActivationFailed::because(
            'migration repository is neither the exact pre-activation nor post-activation state.',
        );
    }

    /** @param array<string, int> $counts */
    private function allCounts(array $counts, int $expected): bool
    {
        foreach ($counts as $count) {
            if ($count !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{id: int, migration: string, batch: int}> $records
     * @param list<string> $names
     */
    private function batchFor(array $records, array $names): int
    {
        $scope = array_fill_keys($names, true);
        $batches = [];

        foreach ($records as $record) {
            if (isset($scope[$record['migration']])) {
                $batches[$record['batch']] = true;
            }
        }

        if (count($batches) !== 1) {
            throw MigrationRecordActivationFailed::because(
                'activated baseline records do not share one migration batch.',
            );
        }

        return (int) array_key_first($batches);
    }

    /**
     * @param list<array{id: int, migration: string, batch: int}> $records
     * @return list<array{id: int, migration: string, batch: int}>
     */
    private function unrelated(array $records, MigrationRecordActivationPlan $plan): array
    {
        $scope = array_fill_keys([...$plan->retiredNames(), ...$plan->baselineNames()], true);

        return array_values(array_filter(
            $records,
            static fn (array $record): bool => ! isset($scope[$record['migration']]),
        ));
    }

    private function assertMigrationName(string $name): void
    {
        if (preg_match('/\A\d{4}_\d{2}_\d{2}_\d{6}_[A-Za-z0-9_]+\z/', $name) !== 1) {
            throw MigrationRecordActivationFailed::because("migration record name [{$name}] is invalid.");
        }
    }

    private function assertWithinRoot(string $root, string $path, string $label): void
    {
        $normalizedRoot = $this->normalizePath($root).'/';
        $normalizedPath = $this->normalizePath($path);

        if (! str_starts_with($normalizedPath, $normalizedRoot)) {
            throw MigrationRecordActivationFailed::because("{$label} [{$path}] escapes the project root.");
        }
    }

    private function assertFingerprint(string $path, string $sha256, string $label): void
    {
        if (! is_file($path) || is_link($path)) {
            throw MigrationRecordActivationFailed::because("{$label} [{$path}] is missing or unsafe.");
        }

        $actual = hash_file('sha256', $path);

        if ($actual === false || ! hash_equals($sha256, $actual)) {
            throw MigrationRecordActivationFailed::because("{$label} [{$path}] changed after planning.");
        }
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/\\'));
    }

    private function result(
        MigrationRecordActivationPlan $plan,
        OutputMode $mode,
        ?int $batch,
        bool $alreadyActivated,
    ): MigrationRecordActivationResult {
        return new MigrationRecordActivationResult(
            $mode,
            $plan->retiredNames(),
            $plan->baselineNames(),
            $batch,
            $alreadyActivated,
        );
    }
}
