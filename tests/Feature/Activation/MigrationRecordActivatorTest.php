<?php

declare(strict_types=1);

namespace Tests\Feature\Activation;

use Cluion\Migrafold\Activation\Exception\MigrationRecordActivationFailed;
use Cluion\Migrafold\Activation\MigrationRecordActivationPlan;
use Cluion\Migrafold\Activation\MigrationRecordActivationPlanner;
use Cluion\Migrafold\Activation\MigrationRecordActivator;
use Cluion\Migrafold\Discovery\DiscoveredMigration;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Discovery\MigrationOwner;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Disposition\SourceDispositionPlan;
use Cluion\Migrafold\Disposition\SourceDispositionPlanner;
use Cluion\Migrafold\Disposition\SourceDispositionWriter;
use Cluion\Migrafold\Output\BaselineOutputPlan;
use Cluion\Migrafold\Output\OutputMode;
use Cluion\Migrafold\Output\OwnerAwareOutputPlan;
use Cluion\Migrafold\Output\OwnerAwareOutputWriter;
use Cluion\Migrafold\Output\OwnerOutputPlan;
use Cluion\Migrafold\Output\PlannedOutputFile;
use Cluion\Migrafold\Output\SourceMigration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MigrationRecordActivatorTest extends TestCase
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

    public function test_planner_builds_exact_deterministic_replacement_scope(): void
    {
        $fixture = $this->fixture();
        $plan = $fixture['activation'];

        self::assertSame([
            '2020_01_01_000000_create_users_table',
            '2020_02_01_000000_add_email_to_users_table',
        ], $plan->retiredNames());
        self::assertSame([
            '2026_09_12_000001_create_users_baseline',
        ], $plan->baselineNames());
        self::assertCount(2, $plan->protectedFiles);

        $incomplete = new SourceDispositionPlan(
            $fixture['disposition']->projectRoot,
            $fixture['disposition']->mode,
            [$fixture['disposition']->items[0]],
            $fixture['disposition']->protectedFiles,
        );

        $this->expectException(MigrationRecordActivationFailed::class);
        $this->expectExceptionMessage('not covered by the source disposition plan');

        (new MigrationRecordActivationPlanner())->plan(
            $fixture['catalog'],
            $fixture['output'],
            $incomplete,
        );
    }

    public function test_dry_run_inspects_ready_repository_without_requiring_source_removal(): void
    {
        $fixture = $this->fixture();
        $this->writeBaselines($fixture);
        $this->seedReadyRepository();

        $result = (new MigrationRecordActivator())->execute(
            $fixture['activation'],
            $this->connection(),
        );

        self::assertFalse($result->applied());
        self::assertFalse($result->alreadyActivated);
        self::assertNull($result->batch);
        self::assertSame([
            '2020_01_01_000000_create_users_table',
            'unrelated_migration',
            '2020_02_01_000000_add_email_to_users_table',
        ], DB::table('migrations')->orderBy('id')->pluck('migration')->all());
        self::assertFileExists($fixture['sources'][0]);
        self::assertFileExists($fixture['sources'][1]);
    }

    public function test_write_rejects_sources_that_are_still_active(): void
    {
        $fixture = $this->fixture();
        $this->writeBaselines($fixture);
        $this->seedReadyRepository();

        try {
            (new MigrationRecordActivator())->execute(
                $fixture['activation'],
                $this->connection(),
                mode: OutputMode::Write,
            );
            self::fail('Expected active sources to block activation.');
        } catch (MigrationRecordActivationFailed $exception) {
            self::assertStringContainsString('is still active', $exception->getMessage());
        }

        self::assertSame(3, DB::table('migrations')->count());
        self::assertSame(0, DB::table('migrations')->where('migration', $fixture['baselineName'])->count());
    }

    public function test_activation_replaces_only_scoped_records_and_is_idempotent(): void
    {
        $fixture = $this->preparedFixture();
        $this->seedReadyRepository();
        $activator = new MigrationRecordActivator();

        $result = $activator->execute(
            $fixture['activation'],
            $this->connection(),
            mode: OutputMode::Write,
        );

        self::assertTrue($result->applied());
        self::assertFalse($result->alreadyActivated);
        self::assertSame(4, $result->batch);
        self::assertSame([
            ['id' => 2, 'migration' => 'unrelated_migration', 'batch' => 2],
            ['id' => 4, 'migration' => $fixture['baselineName'], 'batch' => 4],
        ], DB::table('migrations')->orderBy('id')->get()->map(
            static fn (object $row): array => (array) $row,
        )->all());

        $repeat = $activator->execute(
            $fixture['activation'],
            $this->connection(),
            mode: OutputMode::Write,
        );

        self::assertFalse($repeat->applied());
        self::assertTrue($repeat->alreadyActivated);
        self::assertSame(4, $repeat->batch);
        self::assertSame(2, DB::table('migrations')->count());
    }

    public function test_mixed_repository_state_fails_without_mutation(): void
    {
        $fixture = $this->preparedFixture();
        DB::table('migrations')->insert([
            ['migration' => '2020_01_01_000000_create_users_table', 'batch' => 1],
            ['migration' => $fixture['baselineName'], 'batch' => 2],
        ]);

        $before = DB::table('migrations')->orderBy('id')->get()->map(
            static fn (object $row): array => (array) $row,
        )->all();

        try {
            (new MigrationRecordActivator())->execute(
                $fixture['activation'],
                $this->connection(),
                mode: OutputMode::Write,
            );
            self::fail('Expected mixed repository state to fail closed.');
        } catch (MigrationRecordActivationFailed $exception) {
            self::assertStringContainsString('neither the exact pre-activation nor post-activation state', $exception->getMessage());
        }

        self::assertSame($before, DB::table('migrations')->orderBy('id')->get()->map(
            static fn (object $row): array => (array) $row,
        )->all());
    }

    public function test_duplicate_scoped_record_fails_without_mutation(): void
    {
        $fixture = $this->preparedFixture();
        $this->seedReadyRepository();
        DB::table('migrations')->insert([
            'migration' => '2020_01_01_000000_create_users_table',
            'batch' => 4,
        ]);

        $this->expectException(MigrationRecordActivationFailed::class);
        $this->expectExceptionMessage('neither the exact pre-activation nor post-activation state');

        (new MigrationRecordActivator())->execute(
            $fixture['activation'],
            $this->connection(),
            mode: OutputMode::Write,
        );
    }

    public function test_database_failure_rolls_back_retired_records(): void
    {
        $fixture = $this->preparedFixture();
        $this->seedReadyRepository();
        $this->connection()->unprepared(<<<'SQL'
CREATE TRIGGER reject_migrafold_baseline
BEFORE INSERT ON migrations
WHEN NEW.migration = '2026_09_12_000001_create_users_baseline'
BEGIN
    SELECT RAISE(ABORT, 'simulated activation failure');
END
SQL);

        try {
            (new MigrationRecordActivator())->execute(
                $fixture['activation'],
                $this->connection(),
                mode: OutputMode::Write,
            );
            self::fail('Expected baseline insertion to fail.');
        } catch (MigrationRecordActivationFailed $exception) {
            self::assertStringContainsString('database transaction failed', $exception->getMessage());
        }

        self::assertSame([
            '2020_01_01_000000_create_users_table',
            'unrelated_migration',
            '2020_02_01_000000_add_email_to_users_table',
        ], DB::table('migrations')->orderBy('id')->pluck('migration')->all());
        self::assertSame(0, DB::table('migrations')->where('migration', $fixture['baselineName'])->count());
    }

    public function test_baseline_fingerprint_drift_fails_before_database_mutation(): void
    {
        $fixture = $this->preparedFixture();
        $this->seedReadyRepository();
        self::assertNotFalse(file_put_contents($fixture['baselinePath'], "<?php\n// changed\n"));

        try {
            (new MigrationRecordActivator())->execute(
                $fixture['activation'],
                $this->connection(),
                mode: OutputMode::Write,
            );
            self::fail('Expected baseline drift to fail closed.');
        } catch (MigrationRecordActivationFailed $exception) {
            self::assertStringContainsString('changed after planning', $exception->getMessage());
        }

        self::assertSame(3, DB::table('migrations')->count());
        self::assertSame(0, DB::table('migrations')->where('migration', $fixture['baselineName'])->count());
    }

    /**
     * @return array{
     *     root: string,
     *     catalog: MigrationCatalog,
     *     output: OwnerAwareOutputPlan,
     *     disposition: SourceDispositionPlan,
     *     activation: MigrationRecordActivationPlan,
     *     sources: list<string>,
     *     baselineName: string,
     *     baselinePath: string
     * }
     */
    private function preparedFixture(): array
    {
        $fixture = $this->fixture();
        $this->writeBaselines($fixture);
        (new SourceDispositionWriter())->execute($fixture['disposition'], OutputMode::Write);

        return $fixture;
    }

    /**
     * @return array{
     *     root: string,
     *     catalog: MigrationCatalog,
     *     output: OwnerAwareOutputPlan,
     *     disposition: SourceDispositionPlan,
     *     activation: MigrationRecordActivationPlan,
     *     sources: list<string>,
     *     baselineName: string,
     *     baselinePath: string
     * }
     */
    private function fixture(): array
    {
        $root = $this->root();
        $directory = $root.'/database/migrations';
        $sourcePaths = [
            $this->write(
                $directory.'/2020_01_01_000000_create_users_table.php',
                "<?php\n// create users\n",
            ),
            $this->write(
                $directory.'/2020_02_01_000000_add_email_to_users_table.php',
                "<?php\n// add email\n",
            ),
        ];
        $owner = new MigrationOwner(
            'laravel:application',
            'application',
            $directory,
            [],
            true,
        );
        $migrations = array_map(
            fn (string $path): DiscoveredMigration => $this->discovered($root, $path),
            $sourcePaths,
        );
        $catalog = new MigrationCatalog([$owner], $migrations);
        $baselineName = '2026_09_12_000001_create_users_baseline';
        $output = new OwnerAwareOutputPlan([
            new OwnerOutputPlan(
                $owner->id,
                $owner->name,
                new BaselineOutputPlan(
                    $directory,
                    [new PlannedOutputFile(
                        $baselineName.'.php',
                        "<?php\n// users baseline\n",
                        'users',
                    )],
                    "{\"format\":\"migrafold-test\"}\n",
                ),
            ),
        ]);
        $disposition = (new SourceDispositionPlanner())->plan(
            $root,
            $catalog,
            $output,
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
        );
        $activation = (new MigrationRecordActivationPlanner())->plan(
            $catalog,
            $output,
            $disposition,
        );

        return [
            'root' => $root,
            'catalog' => $catalog,
            'output' => $output,
            'disposition' => $disposition,
            'activation' => $activation,
            'sources' => $sourcePaths,
            'baselineName' => $baselineName,
            'baselinePath' => $directory.'/'.$baselineName.'.php',
        ];
    }

    private function discovered(string $root, string $path): DiscoveredMigration
    {
        $source = SourceMigration::fromFile($root, $path);

        return new DiscoveredMigration(
            pathinfo($path, PATHINFO_FILENAME),
            $path,
            'laravel:application',
            $source,
        );
    }

    /**
     * @param array{output: OwnerAwareOutputPlan} $fixture
     */
    private function writeBaselines(array $fixture): void
    {
        (new OwnerAwareOutputWriter())->execute($fixture['output'], OutputMode::Write);
    }

    private function seedReadyRepository(): void
    {
        DB::table('migrations')->insert([
            ['migration' => '2020_01_01_000000_create_users_table', 'batch' => 1],
            ['migration' => 'unrelated_migration', 'batch' => 2],
            ['migration' => '2020_02_01_000000_add_email_to_users_table', 'batch' => 3],
        ]);
    }

    private function root(): string
    {
        $root = sys_get_temp_dir().'/migrafold-activation-'.bin2hex(random_bytes(8));

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
            if (is_link($directory)) {
                unlink($directory);
            }

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
