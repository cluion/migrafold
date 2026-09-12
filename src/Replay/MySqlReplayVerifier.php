<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Analysis\MigrationCatalogAnalyzer;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Migration\BaselineMigrationGenerator;
use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Cluion\Migrafold\Schema\MySqlSchemaInspector;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final readonly class MySqlReplayVerifier implements MigrationReplayVerifier
{
    private const WORKSPACE_PREFIX = 'migrafold-mysql-replay-';

    public function __construct(
        private DatabaseManager $databases,
        private Filesystem $files,
        private Connection $sourceConnection,
        private BaselineMigrationGenerator $generator = new BaselineMigrationGenerator(),
        private MySqlSchemaInspector $inspector = new MySqlSchemaInspector(),
        private SchemaReplayComparator $comparator = new SchemaReplayComparator(),
        private MigrationCatalogAnalyzer $analyzer = new MigrationCatalogAnalyzer(),
        private BaselineMigrationOrdering $ordering = new BaselineMigrationOrdering(),
        private ?string $temporaryRoot = null,
    ) {}

    public function verify(
        MigrationCatalog $catalog,
        string $date,
        string $migrationTable = 'migrations',
    ): ReplayVerificationResult {
        $migrations = new ReplayMigrationSet($catalog, $this->analyzer);
        $this->assertMigrationTable($migrationTable);
        [$workspace, $workspaceToken] = $this->createWorkspace();
        $sandboxes = new MySqlReplaySandboxManager($this->databases, $this->sourceConnection);
        $sourceSandbox = null;
        $baselineSandbox = null;
        $defaultConnection = $this->databases->getDefaultConnection();

        try {
            $sourceSandbox = $sandboxes->create('source');
            $source = $this->runner()->replay(
                $sourceSandbox->connection,
                $sourceSandbox->connectionName,
                [$migrations->sourcePaths],
                $migrationTable,
                [$migrationTable, MySqlReplaySandboxManager::MARKER_TABLE],
                $this->inspector,
                'source',
            );
            $baselines = $this->generator->generate($source, $date);

            if ($baselines === []) {
                throw ReplayVerificationFailed::because(
                    'source migrations produced no supported application tables.',
                );
            }

            $this->ordering->assertBaselinesRunFirst($baselines, $migrations->analysis->preserved());
            $baselinePaths = $this->writeBaselines($workspace, $baselines);
            $baselineSandbox = $sandboxes->create('baseline');
            $baseline = $this->runner()->replay(
                $baselineSandbox->connection,
                $baselineSandbox->connectionName,
                [$baselinePaths, $migrations->preservedPaths],
                $migrationTable,
                [$migrationTable, MySqlReplaySandboxManager::MARKER_TABLE],
                $this->inspector,
                'baseline',
            );
            $this->comparator->assertEquivalent($source, $baseline);

            $result = new ReplayVerificationResult(
                source: $source,
                baseline: $baseline,
                analysis: $migrations->analysis,
                baselines: $baselines,
                sourceMigrations: count($migrations->sourcePaths),
                baselineMigrations: count($baselinePaths),
                preservedMigrations: count($migrations->preservedPaths),
            );
        } finally {
            $cleanupFailure = $this->cleanupSandboxes($sandboxes, $baselineSandbox, $sourceSandbox);

            try {
                $this->removeWorkspace($workspace, $workspaceToken);
            } catch (Throwable $exception) {
                $cleanupFailure ??= $exception;
            }

            if ($this->databases->getDefaultConnection() !== $defaultConnection) {
                $this->databases->setDefaultConnection($defaultConnection);
                $cleanupFailure ??= ReplayVerificationFailed::because(
                    'default database connection changed during MySQL/MariaDB replay.',
                );
            }

            if ($cleanupFailure !== null) {
                throw ReplayVerificationFailed::because(
                    'MySQL/MariaDB replay cleanup did not complete safely.',
                    $cleanupFailure,
                );
            }
        }

        return $result;
    }

    private function runner(): MigrationSandboxRunner
    {
        return new MigrationSandboxRunner($this->databases, $this->files);
    }

    private function assertMigrationTable(string $migrationTable): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $migrationTable) !== 1) {
            throw ReplayVerificationFailed::because(
                "migration repository table [{$migrationTable}] is invalid for MySQL/MariaDB replay.",
            );
        }

        if (strcasecmp($migrationTable, MySqlReplaySandboxManager::MARKER_TABLE) === 0) {
            throw ReplayVerificationFailed::because(
                "migration repository table [{$migrationTable}] overlaps the sandbox ownership marker.",
            );
        }
    }

    /** @return array{0: string, 1: string} */
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
            if (basename($baseline->filename) !== $baseline->filename
                || ! str_ends_with($baseline->filename, '.php')) {
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

    private function cleanupSandboxes(
        MySqlReplaySandboxManager $manager,
        ?MySqlReplaySandbox $baseline,
        ?MySqlReplaySandbox $source,
    ): ?Throwable {
        $failure = null;

        foreach ([$baseline, $source] as $sandbox) {
            if ($sandbox === null) {
                continue;
            }

            try {
                $manager->destroy($sandbox);
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        return $failure;
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
