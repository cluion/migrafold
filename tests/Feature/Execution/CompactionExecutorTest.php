<?php

declare(strict_types=1);

namespace Tests\Feature\Execution;

use Closure;
use Cluion\Migrafold\Activation\Exception\MigrationRecordActivationFailed;
use Cluion\Migrafold\Activation\MigrationRecordActivator;
use Cluion\Migrafold\Activation\MigrationRecordTransaction;
use Cluion\Migrafold\Discovery\LaravelMigrationSourceAdapter;
use Cluion\Migrafold\Disposition\NativeSourceFileOperator;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Disposition\SourceFileOperator;
use Cluion\Migrafold\Execution\CompactionExecutor;
use Cluion\Migrafold\Execution\Exception\CompactionExecutionFailed;
use Cluion\Migrafold\Execution\SourceRecoveryCheckpointManager;
use Cluion\Migrafold\Planning\CompactionPlan;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Support\MigrationSource;

final class CompactionExecutorTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->roots) as $root) {
            $this->removeDirectory($root);
        }

        parent::tearDown();
    }

    public function test_archive_execution_commits_files_sources_and_records(): void
    {
        $fixture = $this->fixture(SourceDispositionMode::Archive);
        $this->seedRepository($fixture['oldName']);

        $result = (new CompactionExecutor())->execute($fixture['plan'], $this->connection());

        self::assertTrue($result->clean());
        self::assertTrue($result->activation->applied());
        self::assertSame(1, $result->retiredSources);
        self::assertFileDoesNotExist($fixture['source']);
        self::assertNotNull($fixture['archive']);
        self::assertFileExists($fixture['archive']);
        self::assertFileExists($fixture['baseline']);
        self::assertFileExists($fixture['manifest']);
        self::assertSame([
            ['migration' => 'unrelated_migration', 'batch' => 2],
            ['migration' => $fixture['baselineName'], 'batch' => 3],
        ], DB::table('migrations')->orderBy('id')->get(['migration', 'batch'])->map(
            static fn (object $row): array => (array) $row,
        )->all());
        self::assertSame([], glob(dirname($fixture['source']).'/.migrafold-recovery-*') ?: []);
    }

    public function test_delete_execution_discards_every_recovery_copy_after_activation(): void
    {
        $fixture = $this->fixture(SourceDispositionMode::Delete);
        $this->seedRepository($fixture['oldName']);

        $result = (new CompactionExecutor())->execute($fixture['plan'], $this->connection());

        self::assertTrue($result->clean());
        self::assertFileDoesNotExist($fixture['source']);
        self::assertNull($fixture['archive']);
        self::assertFileExists($fixture['baseline']);
        self::assertSame(0, DB::table('migrations')->where('migration', $fixture['oldName'])->count());
        self::assertSame(1, DB::table('migrations')->where('migration', $fixture['baselineName'])->count());
        self::assertSame([], glob(dirname($fixture['source']).'/.migrafold-recovery-*') ?: []);
        self::assertSame([], glob(dirname($fixture['source']).'/.migrafold-disposition-*') ?: []);
    }

    public function test_execution_leaves_preserved_data_migration_and_record_untouched(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });
        $root = $this->root();
        $schemaName = '2020_01_01_000000_create_users_table';
        $dataName = '2030_01_01_000000_seed_system_user';
        $schemaSource = $this->write(
            $root.'/database/migrations/'.$schemaName.'.php',
            MigrationSource::users(),
        );
        $dataSource = $this->write(
            $root.'/database/migrations/'.$dataName.'.php',
            MigrationSource::insertUser(),
        );
        $plan = $this->compactionPlanner()->plan(
            $root,
            $this->connection(),
            [new LaravelMigrationSourceAdapter($root)],
            '2026_09_12',
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
        );
        DB::table('migrations')->insert([
            ['migration' => $schemaName, 'batch' => 1],
            ['migration' => $dataName, 'batch' => 2],
            ['migration' => 'unrelated_migration', 'batch' => 3],
        ]);

        $result = (new CompactionExecutor())->execute($plan, $this->connection());

        self::assertTrue($result->clean());
        self::assertFileDoesNotExist($schemaSource);
        self::assertFileExists($dataSource);
        self::assertSame([
            ['migration' => $dataName, 'batch' => 2],
            ['migration' => 'unrelated_migration', 'batch' => 3],
            ['migration' => $plan->activation->baselineNames()[0], 'batch' => 4],
        ], DB::table('migrations')->orderBy('id')->get(['migration', 'batch'])->map(
            static fn (object $row): array => (array) $row,
        )->all());
    }

    public function test_activation_failure_restores_deleted_sources_and_removes_outputs(): void
    {
        $fixture = $this->fixture(SourceDispositionMode::Delete);
        $this->seedRepository($fixture['oldName']);
        $this->rejectBaselineInsert();

        try {
            (new CompactionExecutor())->execute($fixture['plan'], $this->connection());
            self::fail('Expected activation to fail.');
        } catch (CompactionExecutionFailed $exception) {
            self::assertStringContainsString('database transaction failed', $exception->getMessage());
        }

        self::assertSame(MigrationSource::users(), file_get_contents($fixture['source']));
        self::assertFileDoesNotExist($fixture['baseline']);
        self::assertFileDoesNotExist($fixture['manifest']);
        self::assertSame([
            $fixture['oldName'],
            'unrelated_migration',
        ], DB::table('migrations')->orderBy('id')->pluck('migration')->all());
        self::assertSame([], glob(dirname($fixture['source']).'/.migrafold-recovery-*') ?: []);
        self::assertSame([], glob(dirname($fixture['source']).'/.migrafold-disposition-*') ?: []);
    }

    public function test_activation_failure_restores_archived_sources_and_removes_archive_outputs(): void
    {
        $fixture = $this->fixture(SourceDispositionMode::Archive);
        $this->seedRepository($fixture['oldName']);
        $this->rejectBaselineInsert();

        try {
            (new CompactionExecutor())->execute($fixture['plan'], $this->connection());
            self::fail('Expected activation to fail.');
        } catch (CompactionExecutionFailed $exception) {
            self::assertStringContainsString('database transaction failed', $exception->getMessage());
        }

        self::assertFileExists($fixture['source']);
        self::assertNotNull($fixture['archive']);
        self::assertFileDoesNotExist($fixture['archive']);
        self::assertFileDoesNotExist($fixture['baseline']);
        self::assertFileDoesNotExist($fixture['manifest']);
        self::assertSame(1, DB::table('migrations')->where('migration', $fixture['oldName'])->count());
        self::assertSame(0, DB::table('migrations')->where('migration', $fixture['baselineName'])->count());
        self::assertSame([], glob(dirname($fixture['source']).'/.migrafold-recovery-*') ?: []);
    }

    public function test_committed_repository_state_is_not_rolled_back_after_a_late_activation_error(): void
    {
        $fixture = $this->fixture(SourceDispositionMode::Archive);
        $this->seedRepository($fixture['oldName']);
        $transaction = new class implements MigrationRecordTransaction
        {
            public function run(Connection $connection, string $lockName, Closure $callback): mixed
            {
                $callback();

                throw MigrationRecordActivationFailed::because('simulated error after database commit.');
            }
        };
        $executor = new CompactionExecutor(
            records: new MigrationRecordActivator($transaction),
        );

        $result = $executor->execute($fixture['plan'], $this->connection());

        self::assertFalse($result->clean());
        self::assertTrue($result->activation->alreadyActivated);
        self::assertStringContainsString('simulated error after database commit', $result->warnings[0]);
        self::assertFileDoesNotExist($fixture['source']);
        self::assertNotNull($fixture['archive']);
        self::assertFileExists($fixture['archive']);
        self::assertFileExists($fixture['baseline']);
        self::assertSame(0, DB::table('migrations')->where('migration', $fixture['oldName'])->count());
        self::assertSame(1, DB::table('migrations')->where('migration', $fixture['baselineName'])->count());
        self::assertSame([], glob(dirname($fixture['source']).'/.migrafold-recovery-*') ?: []);
    }

    public function test_failed_source_restore_preserves_the_last_recovery_copy(): void
    {
        $fixture = $this->fixture(SourceDispositionMode::Delete);
        $operator = new class($fixture['source']) implements SourceFileOperator
        {
            private readonly NativeSourceFileOperator $native;

            public function __construct(private readonly string $source)
            {
                $this->native = new NativeSourceFileOperator();
            }

            public function link(string $source, string $target): void
            {
                if ($target === $this->source) {
                    throw CompactionExecutionFailed::because('simulated source restore failure.');
                }

                $this->native->link($source, $target);
            }

            public function unlink(string $path): void
            {
                $this->native->unlink($path);
            }
        };
        $manager = new SourceRecoveryCheckpointManager($operator);
        $checkpoint = $manager->create($fixture['plan']->disposition);
        self::assertTrue(unlink($fixture['source']));

        $errors = $manager->restore($fixture['plan']->disposition, $checkpoint);

        self::assertNotSame([], $errors);
        self::assertStringContainsString('simulated source restore failure', $errors[0]);
        self::assertFileDoesNotExist($fixture['source']);
        self::assertFileExists($checkpoint->copies[0]->path);
        self::assertDirectoryExists($checkpoint->directories[0]);
    }

    /**
     * @return array{
     *     plan: CompactionPlan,
     *     source: string,
     *     archive: string|null,
     *     baseline: string,
     *     manifest: string,
     *     oldName: string,
     *     baselineName: string
     * }
     */
    private function fixture(SourceDispositionMode $mode): array
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });
        $root = $this->root();
        $oldName = '2020_01_01_000000_create_users_table';
        $source = $this->write(
            $root.'/database/migrations/'.$oldName.'.php',
            MigrationSource::users(),
        );
        $plan = $this->compactionPlanner()->plan(
            $root,
            $this->connection(),
            [new LaravelMigrationSourceAdapter($root)],
            '2026_09_12',
            $mode,
            $mode === SourceDispositionMode::Archive ? '2026-09-12T120000Z' : null,
        );
        $baselineName = $plan->activation->baselineNames()[0];
        $directory = dirname($source);

        return [
            'plan' => $plan,
            'source' => $source,
            'archive' => $plan->disposition->items[0]->destination,
            'baseline' => $directory.'/'.$baselineName.'.php',
            'manifest' => $directory.'/.migrafold-manifest.json',
            'oldName' => $oldName,
            'baselineName' => $baselineName,
        ];
    }

    private function seedRepository(string $oldName): void
    {
        DB::table('migrations')->insert([
            ['migration' => $oldName, 'batch' => 1],
            ['migration' => 'unrelated_migration', 'batch' => 2],
        ]);
    }

    private function rejectBaselineInsert(): void
    {
        $this->connection()->unprepared(<<<'SQL'
CREATE TRIGGER reject_migrafold_baseline
BEFORE INSERT ON migrations
WHEN NEW.migration = '2026_09_12_000001_create_users_baseline'
BEGIN
    SELECT RAISE(ABORT, 'simulated activation failure');
END
SQL);
    }

    private function root(): string
    {
        $root = sys_get_temp_dir().'/migrafold-execution-'.bin2hex(random_bytes(8));

        if (! mkdir($root, 0700)) {
            self::fail("Unable to create temporary root [{$root}].");
        }

        $resolved = realpath($root);

        if ($resolved === false) {
            self::fail("Unable to resolve temporary root [{$root}].");
        }

        $this->roots[] = $resolved;

        return $resolved;
    }

    private function write(string $path, string $contents): string
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            self::fail("Unable to create fixture directory [{$directory}].");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("Unable to write fixture [{$path}].");
        }

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            self::fail("Unable to inspect temporary directory [{$directory}].");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
