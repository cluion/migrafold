<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Cluion\Migrafold\Console\PlanCommand;
use Cluion\Migrafold\Planning\CompactionPlanner;
use Cluion\Migrafold\Planning\MigrationSourceAdapterFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

final class PlanCommandTest extends TestCase
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

    public function test_json_plan_command_is_read_only_and_reports_exact_scope(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });
        $root = $this->root();
        $source = $this->write(
            $root.'/database/migrations/2020_01_01_000000_create_users_table.php',
            "<?php\n// source migration\n",
        );
        $tester = $this->tester($root);
        $status = $tester->execute([
            '--connection' => 'testing',
            '--date' => '2026_09_12',
            '--archive-id' => '2026-09-12T120000Z',
            '--json' => true,
        ]);
        $output = $tester->getDisplay();

        self::assertSame(0, $status);
        self::assertStringContainsString('"driver": "sqlite"', $output);
        self::assertStringContainsString('"source_disposition": "archive"', $output);
        self::assertStringContainsString('2020_01_01_000000_create_users_table', $output);
        self::assertStringContainsString('2026_09_12_000001_create_users_baseline', $output);
        self::assertFileExists($source);
        self::assertFileDoesNotExist(
            $root.'/database/migrations/2026_09_12_000001_create_users_baseline.php',
        );
        self::assertDirectoryDoesNotExist($root.'/database/migrations/.migrafold-archive');
        self::assertSame(0, $this->connection()->table('migrations')->count());
    }

    public function test_service_provider_registers_the_planning_command(): void
    {
        $kernel = $this->testApplication()->make(Kernel::class);

        self::assertArrayHasKey('migrafold:plan', $kernel->all());
    }

    public function test_delete_preview_rejects_an_archive_identifier(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        $root = $this->root();
        $source = $this->write(
            $root.'/database/migrations/2020_01_01_000000_create_users_table.php',
            "<?php\n// source migration\n",
        );
        $tester = $this->tester($root);
        $status = $tester->execute([
            '--connection' => 'testing',
            '--date' => '2026_09_12',
            '--delete' => true,
            '--archive-id' => 'not-used',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString('--archive-id cannot be combined with --delete', $tester->getDisplay());
        self::assertFileExists($source);
        self::assertSame(0, $this->connection()->table('migrations')->count());
    }

    private function tester(string $root): CommandTester
    {
        $laravel = new Application($root);
        $command = new PlanCommand(
            $laravel,
            $this->databases(),
            $laravel,
            new MigrationSourceAdapterFactory(),
            new CompactionPlanner(),
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

    private function testApplication(): Application
    {
        if (! $this->app instanceof Application) {
            throw new RuntimeException('Laravel test application is unavailable.');
        }

        return $this->app;
    }

    private function root(): string
    {
        $root = sys_get_temp_dir().'/migrafold-command-'.bin2hex(random_bytes(8));

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
