<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Migration\BaselineMigrationGenerator;
use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use Cluion\Migrafold\Schema\SqliteSchemaInspector;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final readonly class SqliteReplayVerifier
{
    private const WORKSPACE_PREFIX = 'migrafold-replay-';

    public function __construct(
        private DatabaseManager $databases,
        private Filesystem $files,
        private BaselineMigrationGenerator $generator = new BaselineMigrationGenerator(),
        private SqliteSchemaInspector $inspector = new SqliteSchemaInspector(),
        private SchemaReplayComparator $comparator = new SchemaReplayComparator(),
        private ?string $temporaryRoot = null,
    ) {}

    public function verify(
        MigrationCatalog $catalog,
        string $date,
        string $migrationTable = 'migrations',
    ): ReplayVerificationResult {
        $sourcePaths = $this->sourcePaths($catalog);
        $this->assertMigrationTable($migrationTable);
        [$workspace, $token] = $this->createWorkspace();

        try {
            $source = $this->replay(
                $sourcePaths,
                $workspace.'/source.sqlite',
                $token.'-source',
                $migrationTable,
                'source',
            );
            $baselines = $this->generator->generate($source, $date);

            if ($baselines === []) {
                throw ReplayVerificationFailed::because(
                    'source migrations produced no supported application tables.',
                );
            }

            $baselinePaths = $this->writeBaselines($workspace, $baselines);
            $baseline = $this->replay(
                $baselinePaths,
                $workspace.'/baseline.sqlite',
                $token.'-baseline',
                $migrationTable,
                'baseline',
            );
            $this->comparator->assertEquivalent($source, $baseline);

            $result = new ReplayVerificationResult(
                source: $source,
                baseline: $baseline,
                baselines: $baselines,
                sourceMigrations: count($sourcePaths),
                baselineMigrations: count($baselinePaths),
            );
        } finally {
            $this->removeWorkspace($workspace, $token);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function sourcePaths(MigrationCatalog $catalog): array
    {
        if ($catalog->migrations === []) {
            throw ReplayVerificationFailed::because('at least one source migration is required.');
        }

        $paths = [];

        foreach ($catalog->migrations as $migration) {
            if (is_link($migration->absolutePath)) {
                throw ReplayVerificationFailed::because(
                    "source migration [{$migration->source->path}] became a symbolic link after discovery.",
                );
            }

            $resolved = realpath($migration->absolutePath);

            if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
                throw ReplayVerificationFailed::because(
                    "source migration [{$migration->source->path}] is no longer a readable file.",
                );
            }

            $fingerprint = hash_file('sha256', $resolved);

            if (! is_string($fingerprint) || ! hash_equals($migration->source->sha256, $fingerprint)) {
                throw ReplayVerificationFailed::because(
                    "source migration [{$migration->source->path}] changed after discovery.",
                );
            }

            $paths[] = $resolved;
        }

        return $paths;
    }

    private function assertMigrationTable(string $migrationTable): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $migrationTable) !== 1) {
            throw ReplayVerificationFailed::because(
                "migration repository table [{$migrationTable}] is invalid for SQLite replay.",
            );
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createWorkspace(): array
    {
        $configuredRoot = $this->temporaryRoot ?? sys_get_temp_dir();

        if (is_link($configuredRoot)) {
            throw ReplayVerificationFailed::because(
                "temporary root [{$configuredRoot}] must not be a symbolic link.",
            );
        }

        $root = realpath($configuredRoot);

        if ($root === false || ! is_dir($root) || ! is_writable($root)) {
            throw ReplayVerificationFailed::because(
                "temporary root [{$configuredRoot}] is not a writable directory.",
            );
        }

        $token = bin2hex(random_bytes(16));
        $workspace = $root.DIRECTORY_SEPARATOR.self::WORKSPACE_PREFIX.$token;

        if (! $this->files->makeDirectory($workspace, 0700)) {
            throw ReplayVerificationFailed::because(
                "temporary workspace [{$workspace}] could not be created.",
            );
        }

        if ($this->files->put($workspace.'/.migrafold-owner', $token) === false) {
            $this->files->deleteDirectory($workspace);

            throw ReplayVerificationFailed::because(
                "temporary workspace ownership marker [{$workspace}] could not be written.",
            );
        }

        return [$workspace, $token];
    }

    /**
     * @param list<GeneratedMigration> $baselines
     * @return list<string>
     */
    private function writeBaselines(string $workspace, array $baselines): array
    {
        $directory = $workspace.'/baselines';

        if (! $this->files->makeDirectory($directory, 0700)) {
            throw ReplayVerificationFailed::because('baseline staging directory could not be created.');
        }

        $paths = [];

        foreach ($baselines as $baseline) {
            if (
                basename($baseline->filename) !== $baseline->filename
                || ! str_ends_with($baseline->filename, '.php')
            ) {
                throw ReplayVerificationFailed::because(
                    "generated baseline filename [{$baseline->filename}] is unsafe.",
                );
            }

            $path = $directory.'/'.$baseline->filename;

            if ($this->files->put($path, $baseline->contents) === false) {
                throw ReplayVerificationFailed::because(
                    "generated baseline [{$baseline->filename}] could not be staged.",
                );
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * @param list<string> $migrationPaths
     */
    private function replay(
        array $migrationPaths,
        string $databasePath,
        string $connectionName,
        string $migrationTable,
        string $label,
    ): SchemaSnapshot {
        if ($this->files->put($databasePath, '') === false) {
            throw ReplayVerificationFailed::because("{$label} sandbox database could not be created.");
        }

        try {
            $resolved = $this->databases->connectUsing($connectionName, [
                'driver' => 'sqlite',
                'database' => $databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ], true);

            if (! $resolved instanceof Connection) {
                throw ReplayVerificationFailed::because(
                    "{$label} sandbox did not resolve to a Laravel database connection.",
                );
            }

            $repository = new DatabaseMigrationRepository($this->databases, $migrationTable);
            $migrator = new Migrator($repository, $this->databases, $this->files);

            /** @var array<string, string> $ran */
            [$ran, $snapshot] = $migrator->usingConnection(
                $connectionName,
                function () use ($repository, $migrator, $migrationPaths, $resolved, $migrationTable): array {
                    $repository->createRepository();
                    $ran = $migrator->run($migrationPaths);
                    $snapshot = $this->inspector->inspect($resolved, [$migrationTable]);

                    return [$ran, $snapshot];
                },
            );

            if (count($ran) !== count($migrationPaths)) {
                throw ReplayVerificationFailed::because(
                    "{$label} sandbox ran ".count($ran).' of '.count($migrationPaths).' migrations.',
                );
            }

            return $snapshot;
        } catch (ReplayVerificationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReplayVerificationFailed::because(
                $label.' sandbox replay raised ['.$exception::class.'].',
                $exception,
            );
        } finally {
            $this->databases->purge($connectionName);
        }
    }

    private function removeWorkspace(string $workspace, string $token): void
    {
        $root = realpath($this->temporaryRoot ?? sys_get_temp_dir());
        $resolved = realpath($workspace);
        $expected = $root === false
            ? null
            : $root.DIRECTORY_SEPARATOR.self::WORKSPACE_PREFIX.$token;

        if ($resolved === false || $expected === null || $resolved !== $expected) {
            throw ReplayVerificationFailed::because('temporary workspace identity could not be verified for cleanup.');
        }

        $marker = $workspace.'/.migrafold-owner';

        if (! $this->files->isFile($marker) || ! hash_equals($token, $this->files->get($marker))) {
            throw ReplayVerificationFailed::because('temporary workspace ownership marker did not match cleanup scope.');
        }

        if (! $this->files->deleteDirectory($workspace)) {
            throw ReplayVerificationFailed::because('temporary workspace could not be removed after replay.');
        }
    }
}
