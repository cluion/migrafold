<?php

declare(strict_types=1);

namespace Tests\Feature\Disposition;

use Cluion\Migrafold\Discovery\DiscoveredMigration;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Discovery\MigrationOwner;
use Cluion\Migrafold\Disposition\Exception\SourceDispositionFailed;
use Cluion\Migrafold\Disposition\NativeSourceFileOperator;
use Cluion\Migrafold\Disposition\PlannedSourceDisposition;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Disposition\SourceDispositionPlan;
use Cluion\Migrafold\Disposition\SourceDispositionPlanner;
use Cluion\Migrafold\Disposition\SourceDispositionWriter;
use Cluion\Migrafold\Disposition\SourceFileOperator;
use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Output\OutputMode;
use Cluion\Migrafold\Output\OwnerAwareOutputPlan;
use Cluion\Migrafold\Output\OwnerAwareOutputPlanner;
use Cluion\Migrafold\Output\OwnerAwareOutputWriter;
use Cluion\Migrafold\Output\SourceMigration;
use Cluion\Migrafold\Schema\Definition\CapabilityReport;
use Cluion\Migrafold\Schema\Definition\ColumnDefinition;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use Cluion\Migrafold\Schema\Definition\TableDefinition;
use PHPUnit\Framework\TestCase;

final class SourceDispositionWriterTest extends TestCase
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

    public function test_archive_plan_is_deterministic_and_owner_local(): void
    {
        $fixture = $this->fixture();
        $planner = new SourceDispositionPlanner();
        $first = $planner->plan(
            $fixture['root'],
            $fixture['catalog'],
            $fixture['baseline'],
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
        );
        $second = $planner->plan(
            $fixture['root'],
            $fixture['catalog'],
            $fixture['baseline'],
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
        );

        self::assertSame(
            array_column($first->items, 'relativeSource'),
            array_column($second->items, 'relativeSource'),
        );
        self::assertSame(
            array_column($first->items, 'destination'),
            array_column($second->items, 'destination'),
        );
        self::assertSame([
            $fixture['root'].'/app/Modules/Billing/Database/Migrations/.migrafold-archive/2026-09-12T120000Z/2020_01_02_000000_create_invoices_table.php',
            $fixture['root'].'/database/migrations/.migrafold-archive/2026-09-12T120000Z/2020_01_01_000000_create_users_table.php',
        ], array_column($first->items, 'destination'));
        self::assertCount(4, $first->protectedFiles);
    }

    public function test_dry_run_requires_written_baselines_and_does_not_move_sources(): void
    {
        $fixture = $this->fixture();
        $plan = $this->archivePlan($fixture);

        try {
            (new SourceDispositionWriter())->execute($plan);
            self::fail('Expected missing baseline outputs to fail closed.');
        } catch (SourceDispositionFailed $exception) {
            self::assertStringContainsString('protected baseline output', $exception->getMessage());
        }

        self::assertFileExists($fixture['applicationSource']);
        self::assertFileExists($fixture['moduleSource']);

        (new OwnerAwareOutputWriter())->execute($fixture['baseline'], OutputMode::Write);
        $result = (new SourceDispositionWriter())->execute($plan);

        self::assertFalse($result->applied());
        self::assertSame(SourceDispositionMode::Archive, $result->disposition);
        self::assertFileExists($fixture['applicationSource']);
        self::assertFileExists($fixture['moduleSource']);
        self::assertDirectoryDoesNotExist(dirname((string) $plan->items[0]->destination));
    }

    public function test_archive_moves_sources_and_preserves_verified_baselines(): void
    {
        $fixture = $this->fixture();
        $plan = $this->archivePlan($fixture);
        (new OwnerAwareOutputWriter())->execute($fixture['baseline'], OutputMode::Write);
        $result = (new SourceDispositionWriter())->execute($plan, OutputMode::Write);

        self::assertTrue($result->applied());
        self::assertFileDoesNotExist($fixture['applicationSource']);
        self::assertFileDoesNotExist($fixture['moduleSource']);

        foreach ($plan->items as $item) {
            self::assertNotNull($item->destination);
            self::assertFileExists($item->destination);
            self::assertSame($item->sha256, hash_file('sha256', $item->destination));
        }

        foreach ($plan->protectedFiles as $file) {
            self::assertFileExists($file->path);
            self::assertSame($file->sha256, hash_file('sha256', $file->path));
        }
    }

    public function test_delete_removes_sources_without_leaving_recovery_staging(): void
    {
        $fixture = $this->fixture();
        $plan = (new SourceDispositionPlanner())->plan(
            $fixture['root'],
            $fixture['catalog'],
            $fixture['baseline'],
            SourceDispositionMode::Delete,
        );
        (new OwnerAwareOutputWriter())->execute($fixture['baseline'], OutputMode::Write);
        $result = (new SourceDispositionWriter())->execute($plan, OutputMode::Write);

        self::assertTrue($result->applied());
        self::assertSame(SourceDispositionMode::Delete, $result->disposition);
        self::assertFileDoesNotExist($fixture['applicationSource']);
        self::assertFileDoesNotExist($fixture['moduleSource']);
        self::assertSame([], glob(dirname($fixture['applicationSource']).'/.migrafold-disposition-*') ?: []);
        self::assertSame([], glob(dirname($fixture['moduleSource']).'/.migrafold-disposition-*') ?: []);
    }

    public function test_source_fingerprint_drift_aborts_the_entire_operation(): void
    {
        $fixture = $this->fixture();
        $plan = $this->archivePlan($fixture);
        (new OwnerAwareOutputWriter())->execute($fixture['baseline'], OutputMode::Write);
        self::assertNotFalse(file_put_contents($fixture['applicationSource'], "<?php\n// changed\n"));

        $this->expectException(SourceDispositionFailed::class);
        $this->expectExceptionMessage('changed after planning');

        try {
            (new SourceDispositionWriter())->execute($plan, OutputMode::Write);
        } finally {
            self::assertFileExists($fixture['applicationSource']);
            self::assertFileExists($fixture['moduleSource']);
            self::assertDirectoryDoesNotExist(dirname((string) $plan->items[0]->destination));
        }
    }

    public function test_archive_collision_in_one_owner_leaves_every_source_in_place(): void
    {
        $fixture = $this->fixture();
        $plan = $this->archivePlan($fixture);
        (new OwnerAwareOutputWriter())->execute($fixture['baseline'], OutputMode::Write);
        $collision = $plan->items[1]->destination;
        self::assertNotNull($collision);
        self::assertTrue(mkdir(dirname($collision), 0755, true));
        self::assertNotFalse(file_put_contents($collision, "keep\n"));

        try {
            (new SourceDispositionWriter())->execute($plan, OutputMode::Write);
            self::fail('Expected an archive collision to fail closed.');
        } catch (SourceDispositionFailed $exception) {
            self::assertStringContainsString('collides with existing entry', $exception->getMessage());
        }

        self::assertFileExists($fixture['applicationSource']);
        self::assertFileExists($fixture['moduleSource']);
        self::assertSame("keep\n", file_get_contents($collision));
        self::assertDirectoryDoesNotExist(dirname((string) $plan->items[0]->destination));
    }

    public function test_archive_failure_after_one_removal_restores_every_source(): void
    {
        $fixture = $this->fixture();
        $plan = $this->archivePlan($fixture);
        (new OwnerAwareOutputWriter())->execute($fixture['baseline'], OutputMode::Write);
        $operator = $this->failingOnceOn($fixture['applicationSource']);

        try {
            (new SourceDispositionWriter($operator))->execute($plan, OutputMode::Write);
            self::fail('Expected the second source removal to fail.');
        } catch (SourceDispositionFailed $exception) {
            self::assertStringContainsString('simulated source removal failure', $exception->getMessage());
        }

        self::assertSame("<?php\n// users\n", file_get_contents($fixture['applicationSource']));
        self::assertSame("<?php\n// invoices\n", file_get_contents($fixture['moduleSource']));

        foreach ($plan->items as $item) {
            self::assertNotNull($item->destination);
            self::assertFileDoesNotExist($item->destination);
        }
    }

    public function test_delete_failure_after_one_removal_restores_every_source_and_cleans_staging(): void
    {
        $fixture = $this->fixture();
        $plan = (new SourceDispositionPlanner())->plan(
            $fixture['root'],
            $fixture['catalog'],
            $fixture['baseline'],
            SourceDispositionMode::Delete,
        );
        (new OwnerAwareOutputWriter())->execute($fixture['baseline'], OutputMode::Write);
        $operator = $this->failingOnceOn($fixture['applicationSource']);

        try {
            (new SourceDispositionWriter($operator))->execute($plan, OutputMode::Write);
            self::fail('Expected the second source removal to fail.');
        } catch (SourceDispositionFailed $exception) {
            self::assertStringContainsString('simulated source removal failure', $exception->getMessage());
        }

        self::assertSame("<?php\n// users\n", file_get_contents($fixture['applicationSource']));
        self::assertSame("<?php\n// invoices\n", file_get_contents($fixture['moduleSource']));
        self::assertSame([], glob(dirname($fixture['applicationSource']).'/.migrafold-disposition-*') ?: []);
        self::assertSame([], glob(dirname($fixture['moduleSource']).'/.migrafold-disposition-*') ?: []);
    }

    public function test_archive_id_with_traversal_is_rejected_during_planning(): void
    {
        $fixture = $this->fixture();

        $this->expectException(SourceDispositionFailed::class);
        $this->expectExceptionMessage('requires a safe archive id');

        (new SourceDispositionPlanner())->plan(
            $fixture['root'],
            $fixture['catalog'],
            $fixture['baseline'],
            SourceDispositionMode::Archive,
            '../escape',
        );
    }

    public function test_writer_revalidates_archive_destination_from_a_bypassed_plan(): void
    {
        $fixture = $this->fixture();
        $valid = $this->archivePlan($fixture);
        $first = $valid->items[0];
        $items = $valid->items;
        $items[0] = new PlannedSourceDisposition(
            ownerId: $first->ownerId,
            migrationDirectory: $first->migrationDirectory,
            source: $first->source,
            relativeSource: $first->relativeSource,
            sha256: $first->sha256,
            destination: $fixture['root'].'/unrelated/'.basename($first->source),
        );
        $bypassed = new SourceDispositionPlan(
            $valid->projectRoot,
            $valid->mode,
            $items,
            $valid->protectedFiles,
        );
        (new OwnerAwareOutputWriter())->execute($fixture['baseline'], OutputMode::Write);

        $this->expectException(SourceDispositionFailed::class);
        $this->expectExceptionMessage('does not match its source owner');

        try {
            (new SourceDispositionWriter())->execute($bypassed, OutputMode::Write);
        } finally {
            self::assertFileExists($fixture['applicationSource']);
            self::assertFileExists($fixture['moduleSource']);
            self::assertDirectoryDoesNotExist($fixture['root'].'/unrelated');
        }
    }

    /**
     * @param array{
     *     root: string,
     *     catalog: MigrationCatalog,
     *     baseline: OwnerAwareOutputPlan,
     *     applicationSource: string,
     *     moduleSource: string
     * } $fixture
     */
    private function archivePlan(array $fixture): \Cluion\Migrafold\Disposition\SourceDispositionPlan
    {
        return (new SourceDispositionPlanner())->plan(
            $fixture['root'],
            $fixture['catalog'],
            $fixture['baseline'],
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
        );
    }

    private function failingOnceOn(string $path): SourceFileOperator
    {
        return new class($path) implements SourceFileOperator
        {
            private readonly NativeSourceFileOperator $native;

            private bool $failed = false;

            public function __construct(private readonly string $failurePath)
            {
                $this->native = new NativeSourceFileOperator();
            }

            public function link(string $source, string $target): void
            {
                $this->native->link($source, $target);
            }

            public function unlink(string $path): void
            {
                if (! $this->failed && $path === $this->failurePath) {
                    $this->failed = true;

                    throw SourceDispositionFailed::because('simulated source removal failure.');
                }

                $this->native->unlink($path);
            }
        };
    }

    /**
     * @return array{
     *     root: string,
     *     catalog: MigrationCatalog,
     *     baseline: OwnerAwareOutputPlan,
     *     applicationSource: string,
     *     moduleSource: string
     * }
     */
    private function fixture(): array
    {
        $root = $this->root();
        $applicationSource = $this->write(
            $root.'/database/migrations/2020_01_01_000000_create_users_table.php',
            "<?php\n// users\n",
        );
        $moduleSource = $this->write(
            $root.'/app/Modules/Billing/Database/Migrations/2020_01_02_000000_create_invoices_table.php',
            "<?php\n// invoices\n",
        );
        $owners = [
            new MigrationOwner(
                'moduark:Billing',
                'Billing',
                dirname($moduleSource),
                ['invoices'],
            ),
            new MigrationOwner(
                'laravel:application',
                'application',
                dirname($applicationSource),
                [],
                true,
            ),
        ];
        $catalog = new MigrationCatalog($owners, [
            $this->discovered($root, $moduleSource, 'moduark:Billing'),
            $this->discovered($root, $applicationSource, 'laravel:application'),
        ]);
        $snapshot = $this->snapshot();
        $baseline = (new OwnerAwareOutputPlanner())->plan($snapshot, [
            new GeneratedMigration(
                '2026_09_12_000001_create_users_baseline.php',
                'users',
                "<?php\n// users baseline\n",
            ),
            new GeneratedMigration(
                '2026_09_12_000002_create_invoices_baseline.php',
                'invoices',
                "<?php\n// invoices baseline\n",
            ),
        ], $catalog);

        return [
            'root' => $root,
            'catalog' => $catalog,
            'baseline' => $baseline,
            'applicationSource' => $applicationSource,
            'moduleSource' => $moduleSource,
        ];
    }

    private function discovered(string $root, string $path, string $ownerId): DiscoveredMigration
    {
        $source = SourceMigration::fromFile($root, $path);

        return new DiscoveredMigration(
            pathinfo($path, PATHINFO_FILENAME),
            $path,
            $ownerId,
            $source,
        );
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
        $root = sys_get_temp_dir().'/migrafold-disposition-'.bin2hex(random_bytes(8));

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
