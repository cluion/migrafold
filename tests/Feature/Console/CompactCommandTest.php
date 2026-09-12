<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Cluion\Migrafold\Activation\MigrationTableNameResolver;
use Cluion\Migrafold\Console\CompactCommand;
use Cluion\Migrafold\Discovery\LaravelMigrationSourceAdapter;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Execution\CompactionExecutor;
use Cluion\Migrafold\Planning\CompactionPlan;
use Cluion\Migrafold\Planning\CompactionPlanner;
use Cluion\Migrafold\Planning\MigrationSourceAdapterFactory;
use Cluion\Migrafold\Schema\Definition\TableDefinition;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

final class CompactCommandTest extends TestCase
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

    public function test_service_provider_registers_the_execution_command(): void
    {
        $kernel = $this->testApplication()->make(Kernel::class);

        self::assertArrayHasKey('migrafold:compact', $kernel->all());
    }

    public function test_stale_fingerprint_refuses_archive_execution_without_changes(): void
    {
        $fixture = $this->fixture(SourceDispositionMode::Archive);
        $stale = $fixture['plan']->fingerprint();
        self::assertNotFalse(file_put_contents($fixture['source'], "<?php\n// changed source migration\n"));

        $tester = $this->tester($fixture['root']);
        $status = $tester->execute([
            '--connection' => 'testing',
            '--date' => '2026_09_12',
            '--archive-id' => '2026-09-12T120000Z',
            '--confirm' => $stale,
            '--no-interaction' => true,
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString('does not match the current plan fingerprint', $tester->getDisplay());
        self::assertFileExists($fixture['source']);
        self::assertFileDoesNotExist($fixture['baseline']);
        self::assertFileDoesNotExist($fixture['manifest']);
        self::assertNotNull($fixture['archive']);
        self::assertFileDoesNotExist($fixture['archive']);
        self::assertSame(1, DB::table('migrations')->where('migration', $fixture['oldName'])->count());
    }

    public function test_archive_execution_commits_only_after_exact_confirmation(): void
    {
        $fixture = $this->fixture(SourceDispositionMode::Archive);
        $tester = $this->tester($fixture['root']);
        $status = $tester->execute([
            '--connection' => 'testing',
            '--date' => '2026_09_12',
            '--archive-id' => '2026-09-12T120000Z',
            '--confirm' => $fixture['plan']->fingerprint(),
            '--no-interaction' => true,
        ]);

        self::assertSame(0, $status);
        self::assertStringContainsString('compaction completed successfully', $tester->getDisplay());
        self::assertStringContainsString($fixture['plan']->fingerprint(), $tester->getDisplay());
        self::assertFileDoesNotExist($fixture['source']);
        self::assertNotNull($fixture['archive']);
        self::assertFileExists($fixture['archive']);
        self::assertFileExists($fixture['baseline']);
        self::assertFileExists($fixture['manifest']);
        self::assertSame([
            'unrelated_migration',
            $fixture['baselineName'],
        ], DB::table('migrations')->orderBy('id')->pluck('migration')->all());
    }

    public function test_delete_execution_requires_a_second_exact_confirmation(): void
    {
        $fixture = $this->fixture(SourceDispositionMode::Delete);
        $tester = $this->tester($fixture['root']);
        $status = $tester->execute([
            '--connection' => 'testing',
            '--date' => '2026_09_12',
            '--delete' => true,
            '--confirm' => $fixture['plan']->fingerprint(),
        ], ['interactive' => false]);

        self::assertSame(1, $status);
        self::assertStringContainsString('--confirm-delete='.$fixture['plan']->fingerprint(), $tester->getDisplay());
        self::assertFileExists($fixture['source']);
        self::assertFileDoesNotExist($fixture['baseline']);
        self::assertFileDoesNotExist($fixture['manifest']);
        self::assertSame(1, DB::table('migrations')->where('migration', $fixture['oldName'])->count());
    }

    public function test_delete_execution_commits_after_both_exact_confirmations(): void
    {
        $fixture = $this->fixture(SourceDispositionMode::Delete);
        $fingerprint = $fixture['plan']->fingerprint();
        $tester = $this->tester($fixture['root']);
        $status = $tester->execute([
            '--connection' => 'testing',
            '--date' => '2026_09_12',
            '--delete' => true,
            '--confirm' => $fingerprint,
            '--confirm-delete' => $fingerprint,
            '--no-interaction' => true,
        ]);

        self::assertSame(0, $status);
        self::assertFileDoesNotExist($fixture['source']);
        self::assertFileExists($fixture['baseline']);
        self::assertFileExists($fixture['manifest']);
        self::assertSame(0, DB::table('migrations')->where('migration', $fixture['oldName'])->count());
        self::assertSame(1, DB::table('migrations')->where('migration', $fixture['baselineName'])->count());
    }

    public function test_configured_migration_table_is_excluded_and_updated(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('custom_history', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $this->config()->set('database.migrations.table', 'custom_history');
        $root = $this->root();
        $oldName = '2020_01_01_000000_create_users_table';
        $source = $this->write(
            $root.'/database/migrations/'.$oldName.'.php',
            "<?php\n// source migration\n",
        );
        $plan = (new CompactionPlanner())->plan(
            $root,
            $this->connection(),
            [new LaravelMigrationSourceAdapter($root)],
            '2026_09_12',
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
            'custom_history',
        );
        $baselineName = $plan->activation->baselineNames()[0];
        DB::table('custom_history')->insert([
            'migration' => $oldName,
            'batch' => 1,
        ]);
        $tester = $this->tester($root);
        $status = $tester->execute([
            '--connection' => 'testing',
            '--date' => '2026_09_12',
            '--archive-id' => '2026-09-12T120000Z',
            '--confirm' => $plan->fingerprint(),
            '--no-interaction' => true,
        ]);

        self::assertSame(['users'], array_map(
            static fn (TableDefinition $table): string => $table->name,
            $plan->snapshot->tables,
        ));
        self::assertSame(0, $status);
        self::assertStringContainsString('Migration table: custom_history', $tester->getDisplay());
        self::assertFileDoesNotExist($source);
        self::assertSame([$baselineName], DB::table('custom_history')->pluck('migration')->all());
        self::assertSame(0, DB::table('migrations')->count());
    }

    /**
     * @return array{
     *     root: string,
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
            "<?php\n// source migration\n",
        );
        $plan = (new CompactionPlanner())->plan(
            $root,
            $this->connection(),
            [new LaravelMigrationSourceAdapter($root)],
            '2026_09_12',
            $mode,
            $mode === SourceDispositionMode::Archive ? '2026-09-12T120000Z' : null,
        );
        $baselineName = $plan->activation->baselineNames()[0];
        DB::table('migrations')->insert([
            ['migration' => $oldName, 'batch' => 1],
            ['migration' => 'unrelated_migration', 'batch' => 2],
        ]);

        return [
            'root' => $root,
            'plan' => $plan,
            'source' => $source,
            'archive' => $plan->disposition->items[0]->destination,
            'baseline' => dirname($source).'/'.$baselineName.'.php',
            'manifest' => dirname($source).'/.migrafold-manifest.json',
            'oldName' => $oldName,
            'baselineName' => $baselineName,
        ];
    }

    private function tester(string $root): CommandTester
    {
        $laravel = new Application($root);
        $command = new CompactCommand(
            $laravel,
            $this->databases(),
            $laravel,
            new MigrationTableNameResolver($this->config()),
            new MigrationSourceAdapterFactory(),
            new CompactionPlanner(),
            new CompactionExecutor(),
        );
        $command->setLaravel($this->testApplication());
        $console = new ConsoleApplication();
        $console->addCommand($command);

        return new CommandTester($command);
    }

    private function databases(): DatabaseManager
    {
        $manager = $this->testApplication()->make('db');

        if (! $manager instanceof DatabaseManager) {
            throw new RuntimeException('Laravel database manager is unavailable.');
        }

        return $manager;
    }

    private function config(): ConfigRepository
    {
        $config = $this->testApplication()->make('config');

        if (! $config instanceof ConfigRepository) {
            throw new RuntimeException('Laravel configuration repository is unavailable.');
        }

        return $config;
    }

    private function testApplication(): Application
    {
        if (! $this->app instanceof Application) {
            throw new RuntimeException('Laravel test application is unavailable.');
        }

        return $this->app;
    }

    private function root(): string
    {
        $root = sys_get_temp_dir().'/migrafold-compact-command-'.bin2hex(random_bytes(8));

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
