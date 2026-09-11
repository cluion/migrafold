<?php

declare(strict_types=1);

namespace Tests\Feature\Output;

use Cluion\Migrafold\Discovery\DiscoveredMigration;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Discovery\MigrationOwner;
use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Output\AtomicFilePublisher;
use Cluion\Migrafold\Output\BaselineOutputPlan;
use Cluion\Migrafold\Output\BaselineOutputWriter;
use Cluion\Migrafold\Output\Exception\UnsafeOutputOperation;
use Cluion\Migrafold\Output\OutputMode;
use Cluion\Migrafold\Output\OwnerAwareOutputPlanner;
use Cluion\Migrafold\Output\OwnerAwareOutputWriter;
use Cluion\Migrafold\Output\SourceMigration;
use Cluion\Migrafold\Schema\Definition\CapabilityReport;
use Cluion\Migrafold\Schema\Definition\ColumnDefinition;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use Cluion\Migrafold\Schema\Definition\TableDefinition;
use PHPUnit\Framework\TestCase;

final class OwnerAwareOutputWriterTest extends TestCase
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

    public function test_it_groups_outputs_and_sources_into_deterministic_per_owner_manifests(): void
    {
        $root = $this->root();
        $plan = (new OwnerAwareOutputPlanner())->plan(
            $this->snapshot(),
            array_reverse($this->migrations()),
            $this->catalog($root),
        );

        self::assertSame(['laravel:application', 'moduark:Billing'], array_column($plan->owners, 'ownerId'));
        self::assertSame(
            [$root.'/database/migrations', $root.'/app/Modules/Billing/Database/Migrations'],
            array_map(static fn ($owner): string => $owner->output->directory, $plan->owners),
        );
        self::assertSame(['users'], array_column($plan->owners[0]->output->migrations, 'table'));
        self::assertSame(['invoices'], array_column($plan->owners[1]->output->migrations, 'table'));

        $applicationManifest = $plan->owners[0]->output->manifestContents;
        $moduleManifest = $plan->owners[1]->output->manifestContents;

        self::assertStringContainsString('"id": "laravel:application"', $applicationManifest);
        self::assertStringContainsString('"name": "application"', $applicationManifest);
        self::assertStringContainsString(
            '"path": "database/migrations/2020_01_01_000000_create_users_table.php"',
            $applicationManifest,
        );
        self::assertStringNotContainsString('create_invoices_table.php', $applicationManifest);
        self::assertStringContainsString('"id": "moduark:Billing"', $moduleManifest);
        self::assertStringContainsString('"name": "Billing"', $moduleManifest);
        self::assertStringContainsString(
            '"path": "app/Modules/Billing/Database/Migrations/2020_01_02_000000_create_invoices_table.php"',
            $moduleManifest,
        );
        self::assertStringNotContainsString('create_users_table.php', $moduleManifest);
        self::assertStringContainsString('"sha256": "'.$this->snapshot()->fingerprint().'"', $moduleManifest);
    }

    public function test_dry_run_reports_every_owner_without_creating_directories(): void
    {
        $root = $this->root();
        $plan = (new OwnerAwareOutputPlanner())->plan(
            $this->snapshot(),
            $this->migrations(),
            $this->catalog($root),
        );
        $result = (new OwnerAwareOutputWriter())->execute($plan);

        self::assertSame(OutputMode::DryRun, $result->mode);
        self::assertFalse($result->written());
        self::assertSame(['laravel:application', 'moduark:Billing'], array_column($result->owners, 'ownerId'));
        self::assertSame($plan->paths(), $result->paths());
        self::assertDirectoryDoesNotExist($root.'/database');
        self::assertDirectoryDoesNotExist($root.'/app');
    }

    public function test_write_publishes_every_owner_plan_and_manifest(): void
    {
        $root = $this->root();
        $plan = (new OwnerAwareOutputPlanner())->plan(
            $this->snapshot(),
            $this->migrations(),
            $this->catalog($root),
        );
        $result = (new OwnerAwareOutputWriter())->execute($plan, OutputMode::Write);

        self::assertTrue($result->written());
        self::assertSame($plan->paths(), $result->paths());

        foreach ($plan->paths() as $path) {
            self::assertFileExists($path);
        }

        foreach ($plan->owners as $owner) {
            self::assertSame([], glob($owner->output->directory.'/.migrafold-write-*') ?: []);
        }
    }

    public function test_a_collision_in_any_owner_prevents_writes_to_every_owner(): void
    {
        $root = $this->root();
        $moduleDirectory = $root.'/app/Modules/Billing/Database/Migrations';
        self::assertTrue(mkdir($moduleDirectory, 0755, true));
        self::assertNotFalse(file_put_contents($moduleDirectory.'/'.BaselineOutputPlan::MANIFEST_FILENAME, "keep\n"));
        $plan = (new OwnerAwareOutputPlanner())->plan(
            $this->snapshot(),
            $this->migrations(),
            $this->catalog($root),
        );

        try {
            (new OwnerAwareOutputWriter())->execute($plan, OutputMode::Write);
            self::fail('Expected the global preflight to reject the collision.');
        } catch (UnsafeOutputOperation $exception) {
            self::assertStringContainsString('collides with existing entry', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($root.'/database');
        self::assertSame("keep\n", file_get_contents($moduleDirectory.'/'.BaselineOutputPlan::MANIFEST_FILENAME));
        self::assertCount(1, array_diff(scandir($moduleDirectory) ?: [], ['.', '..']));
    }

    public function test_a_late_owner_failure_rolls_back_outputs_written_for_earlier_owners(): void
    {
        $root = $this->root();
        $plan = (new OwnerAwareOutputPlanner())->plan(
            $this->snapshot(),
            $this->migrations(),
            $this->catalog($root),
        );
        $publisher = new class implements AtomicFilePublisher
        {
            private int $published = 0;

            public function publish(string $staged, string $target): void
            {
                if ($this->published >= 2) {
                    throw UnsafeOutputOperation::because('simulated second-owner publication failure.');
                }

                if (! link($staged, $target)) {
                    throw UnsafeOutputOperation::because('simulated publication failure.');
                }

                $this->published++;
            }
        };

        try {
            (new OwnerAwareOutputWriter(new BaselineOutputWriter($publisher)))
                ->execute($plan, OutputMode::Write);
            self::fail('Expected the second owner publication to fail.');
        } catch (UnsafeOutputOperation $exception) {
            self::assertStringContainsString('simulated second-owner publication failure', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($root.'/database/migrations');
        self::assertDirectoryDoesNotExist($root.'/app/Modules/Billing/Database/Migrations');
    }

    public function test_owners_cannot_share_the_same_output_directory(): void
    {
        $root = $this->root();
        $directory = $root.'/database/migrations';
        $catalog = new MigrationCatalog([
            new MigrationOwner('laravel:application', 'application', $directory, [], true),
            new MigrationOwner('moduark:Billing', 'Billing', $directory, ['invoices']),
        ], []);

        $this->expectException(UnsafeOutputOperation::class);
        $this->expectExceptionMessage('share output directory');

        (new OwnerAwareOutputPlanner())->plan($this->snapshot(), $this->migrations(), $catalog);
    }

    public function test_duplicate_filenames_across_owners_are_rejected_before_planning(): void
    {
        $root = $this->root();
        $migrations = $this->migrations();
        $migrations[1] = new GeneratedMigration(
            $migrations[0]->filename,
            $migrations[1]->table,
            $migrations[1]->contents,
        );
        $migrations = array_values($migrations);

        $this->expectException(UnsafeOutputOperation::class);
        $this->expectExceptionMessage('duplicate generated migration filename');

        (new OwnerAwareOutputPlanner())->plan($this->snapshot(), $migrations, $this->catalog($root));
    }

    private function catalog(string $root): MigrationCatalog
    {
        return new MigrationCatalog([
            new MigrationOwner(
                'moduark:Billing',
                'Billing',
                $root.'/app/Modules/Billing/Database/Migrations',
                ['invoices'],
            ),
            new MigrationOwner(
                'laravel:application',
                'application',
                $root.'/database/migrations',
                [],
                true,
            ),
        ], [
            $this->source(
                $root,
                'moduark:Billing',
                'app/Modules/Billing/Database/Migrations/2020_01_02_000000_create_invoices_table.php',
                'b',
            ),
            $this->source(
                $root,
                'laravel:application',
                'database/migrations/2020_01_01_000000_create_users_table.php',
                'a',
            ),
        ]);
    }

    private function source(string $root, string $ownerId, string $path, string $hashCharacter): DiscoveredMigration
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        $source = new SourceMigration($path, str_repeat($hashCharacter, 64));

        return new DiscoveredMigration(
            $name,
            $root.'/'.$path,
            $ownerId,
            $source,
        );
    }

    /** @return list<GeneratedMigration> */
    private function migrations(): array
    {
        return [
            new GeneratedMigration(
                '2026_09_12_000001_create_users_baseline.php',
                'users',
                "<?php\n// users\n",
            ),
            new GeneratedMigration(
                '2026_09_12_000002_create_invoices_baseline.php',
                'invoices',
                "<?php\n// invoices\n",
            ),
        ];
    }

    private function snapshot(): SchemaSnapshot
    {
        return new SchemaSnapshot(
            formatVersion: 'schema-ir-v1',
            driver: 'sqlite',
            capabilities: new CapabilityReport(['columns'], []),
            tables: [$this->table('users'), $this->table('invoices')],
        );
    }

    private function table(string $name): TableDefinition
    {
        return new TableDefinition(
            name: $name,
            schema: null,
            collation: null,
            engine: null,
            comment: null,
            columns: [
                new ColumnDefinition('id', 'integer', 'integer', false, null, true, null, null, null),
            ],
            indexes: [],
            foreignKeys: [],
        );
    }

    private function root(): string
    {
        $root = sys_get_temp_dir().'/migrafold-owner-output-'.bin2hex(random_bytes(8));

        if (! mkdir($root, 0700)) {
            self::fail("Unable to create temporary root [{$root}].");
        }

        $this->roots[] = $root;

        return $root;
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
