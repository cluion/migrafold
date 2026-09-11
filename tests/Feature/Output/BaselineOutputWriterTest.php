<?php

declare(strict_types=1);

namespace Tests\Feature\Output;

use Cluion\Migrafold\Migration\BaselineMigrationGenerator;
use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Output\AtomicFilePublisher;
use Cluion\Migrafold\Output\BaselineOutputPlan;
use Cluion\Migrafold\Output\BaselineOutputPlanner;
use Cluion\Migrafold\Output\BaselineOutputWriter;
use Cluion\Migrafold\Output\Exception\UnsafeOutputOperation;
use Cluion\Migrafold\Output\OutputMode;
use Cluion\Migrafold\Output\PlannedOutputFile;
use Cluion\Migrafold\Output\SourceMigration;
use Cluion\Migrafold\Schema\Definition\CapabilityReport;
use Cluion\Migrafold\Schema\Definition\ColumnDefinition;
use Cluion\Migrafold\Schema\Definition\IndexDefinition;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use Cluion\Migrafold\Schema\Definition\TableDefinition;
use PHPUnit\Framework\TestCase;

final class BaselineOutputWriterTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_dry_run_returns_every_target_without_creating_the_directory(): void
    {
        $root = $this->temporaryDirectory();
        $output = $root.'/database/migrations';
        $plan = $this->plan($output);

        $result = (new BaselineOutputWriter())->execute($plan);

        self::assertFalse($result->written());
        self::assertSame(OutputMode::DryRun, $result->mode);
        self::assertSame([
            $output.'/2026_09_11_000001_create_users_baseline.php',
            $output.'/'.BaselineOutputPlan::MANIFEST_FILENAME,
        ], $result->paths);
        self::assertDirectoryDoesNotExist($output);
    }

    public function test_write_publishes_verified_migrations_and_manifest_last(): void
    {
        $root = $this->temporaryDirectory();
        $sourceDirectory = $root.'/database/migrations';
        self::assertTrue(mkdir($sourceDirectory, 0755, true));
        $sourcePath = $sourceDirectory.'/2020_01_01_000000_create_users_table.php';
        self::assertNotFalse(file_put_contents($sourcePath, "<?php\n// legacy\n"));
        $source = SourceMigration::fromFile($root, 'database/migrations/2020_01_01_000000_create_users_table.php');
        $output = $root.'/database/baselines';
        $plan = $this->plan($output, [$source]);

        $result = (new BaselineOutputWriter())->execute($plan, OutputMode::Write);

        self::assertTrue($result->written());
        self::assertSame($plan->migrations[0]->contents, file_get_contents($result->paths[0]));
        self::assertSame($plan->manifestContents, file_get_contents($result->paths[1]));

        self::assertSame([
            'format_version' => 'migrafold-manifest-v1',
            'schema' => [
                'format_version' => 'schema-ir-v1',
                'driver' => 'sqlite',
                'sha256' => $this->snapshot()->fingerprint(),
            ],
            'sources' => [$source->toArray()],
            'outputs' => [[
                'path' => '2026_09_11_000001_create_users_baseline.php',
                'table' => 'users',
                'sha256' => hash('sha256', $plan->migrations[0]->contents),
            ]],
        ], json_decode($plan->manifestContents, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame([], glob($output.'/.migrafold-write-*') ?: []);
    }

    public function test_existing_case_variant_collision_is_rejected_without_overwrite(): void
    {
        $root = $this->temporaryDirectory();
        $output = $root.'/database/migrations';
        self::assertTrue(mkdir($output, 0755, true));
        $existing = $output.'/2026_09_11_000001_CREATE_USERS_BASELINE.PHP';
        self::assertNotFalse(file_put_contents($existing, 'keep'));

        try {
            (new BaselineOutputWriter())->execute($this->plan($output), OutputMode::Write);
            self::fail('Expected a collision to fail closed.');
        } catch (UnsafeOutputOperation $exception) {
            self::assertStringContainsString('MGF-OUTPUT-001', $exception->getMessage());
            self::assertStringContainsString('collides with existing entry', $exception->getMessage());
        }

        self::assertSame('keep', file_get_contents($existing));
        self::assertFileDoesNotExist($output.'/'.BaselineOutputPlan::MANIFEST_FILENAME);
    }

    public function test_a_second_write_is_rejected_and_preserves_the_first_result(): void
    {
        $root = $this->temporaryDirectory();
        $output = $root.'/database/migrations';
        $plan = $this->plan($output);
        $writer = new BaselineOutputWriter();
        $writer->execute($plan, OutputMode::Write);
        $before = array_map('file_get_contents', $plan->paths());

        $this->expectException(UnsafeOutputOperation::class);
        $this->expectExceptionMessage('MGF-OUTPUT-001');

        try {
            $writer->execute($plan, OutputMode::Write);
        } finally {
            self::assertSame($before, array_map('file_get_contents', $plan->paths()));
        }
    }

    public function test_a_partial_publication_failure_rolls_back_only_files_created_by_the_writer(): void
    {
        $root = $this->temporaryDirectory();
        $output = $root.'/output';
        $plan = $this->plan($output);
        $publisher = new class implements AtomicFilePublisher
        {
            private int $published = 0;

            public function publish(string $staged, string $target): void
            {
                if ($this->published > 0) {
                    throw UnsafeOutputOperation::because('simulated manifest publication failure.');
                }

                if (! link($staged, $target)) {
                    throw UnsafeOutputOperation::because('simulated first publication failure.');
                }

                $this->published++;
            }
        };

        try {
            (new BaselineOutputWriter($publisher))->execute($plan, OutputMode::Write);
            self::fail('Expected the simulated publisher to fail.');
        } catch (UnsafeOutputOperation $exception) {
            self::assertStringContainsString('simulated manifest publication failure', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($output);
    }

    public function test_unsafe_generated_filenames_are_rejected_before_any_write(): void
    {
        $root = $this->temporaryDirectory();
        $output = $root.'/database/migrations';
        $migration = new GeneratedMigration('../escape.php', 'users', "<?php\n");

        $this->expectException(UnsafeOutputOperation::class);
        $this->expectExceptionMessage('generated migration filename [../escape.php] is unsafe');

        try {
            (new BaselineOutputPlanner())->plan($output, $this->snapshot(), [$migration], []);
        } finally {
            self::assertDirectoryDoesNotExist($output);
        }
    }

    public function test_writer_revalidates_a_plan_that_bypassed_the_planner(): void
    {
        $root = $this->temporaryDirectory();
        $plan = new BaselineOutputPlan(
            $root.'/output',
            [new PlannedOutputFile('../escape.php', "<?php\n", 'users')],
            "{}\n",
        );

        $this->expectException(UnsafeOutputOperation::class);
        $this->expectExceptionMessage('planned output filename [../escape.php] is unsafe');

        try {
            (new BaselineOutputWriter())->execute($plan, OutputMode::Write);
        } finally {
            self::assertFileDoesNotExist($root.'/escape.php');
        }
    }

    public function test_sources_are_sorted_and_duplicate_paths_are_rejected_case_insensitively(): void
    {
        $root = $this->temporaryDirectory();
        $planner = new BaselineOutputPlanner();
        $migrations = (new BaselineMigrationGenerator())->generate($this->snapshot(), '2026_09_11');
        $sources = [
            new SourceMigration('Modules/Users/database/migrations/b.php', str_repeat('b', 64)),
            new SourceMigration('database/migrations/a.php', str_repeat('a', 64)),
        ];
        $plan = $planner->plan($root.'/output', $this->snapshot(), $migrations, $sources);
        $reversePlan = $planner->plan($root.'/other', $this->snapshot(), $migrations, array_reverse($sources));

        self::assertSame($plan->manifestContents, $reversePlan->manifestContents);

        $this->expectException(UnsafeOutputOperation::class);
        $this->expectExceptionMessage('duplicate source migration');
        $planner->plan($root.'/other', $this->snapshot(), $migrations, [
            new SourceMigration('database/migrations/A.php', str_repeat('a', 64)),
            new SourceMigration('database/migrations/a.php', str_repeat('b', 64)),
        ]);
    }

    public function test_source_files_outside_the_project_or_behind_symlinks_are_rejected(): void
    {
        $root = $this->temporaryDirectory();
        $outside = $this->temporaryDirectory().'/outside.php';
        self::assertNotFalse(file_put_contents($outside, "<?php\n"));

        try {
            SourceMigration::fromFile($root, $outside);
            self::fail('Expected an outside source to be rejected.');
        } catch (UnsafeOutputOperation $exception) {
            self::assertStringContainsString('outside project root', $exception->getMessage());
        }

        $directory = $root.'/database/migrations';
        self::assertTrue(mkdir($directory, 0755, true));
        $link = $directory.'/linked.php';
        self::assertTrue(symlink($outside, $link));

        $this->expectException(UnsafeOutputOperation::class);
        $this->expectExceptionMessage('must not be a symbolic link');
        SourceMigration::fromFile($root, 'database/migrations/linked.php');
    }

    /**
     * @param list<SourceMigration> $sources
     */
    private function plan(string $output, array $sources = []): BaselineOutputPlan
    {
        $snapshot = $this->snapshot();
        $migrations = (new BaselineMigrationGenerator())->generate($snapshot, '2026_09_11');

        return (new BaselineOutputPlanner())->plan($output, $snapshot, $migrations, $sources);
    }

    private function snapshot(): SchemaSnapshot
    {
        return new SchemaSnapshot(
            formatVersion: 'schema-ir-v1',
            driver: 'sqlite',
            capabilities: new CapabilityReport(['columns'], []),
            tables: [
                new TableDefinition(
                    name: 'users',
                    schema: null,
                    collation: null,
                    engine: null,
                    comment: null,
                    columns: [
                        new ColumnDefinition('id', 'integer', 'integer', false, null, true, null, null, null),
                    ],
                    indexes: [
                        new IndexDefinition('primary', ['id'], 'btree', true, true),
                    ],
                    foreignKeys: [],
                ),
            ],
        );
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/migrafold-output-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700)) {
            self::fail("Unable to create temporary directory [{$directory}].");
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
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
