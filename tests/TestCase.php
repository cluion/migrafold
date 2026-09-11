<?php

declare(strict_types=1);

namespace Tests;

use Cluion\Migrafold\MigrafoldServiceProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use LogicException;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RuntimeException;

abstract class TestCase extends OrchestraTestCase
{
    /** @var list<string> */
    private array $fixtureDirectories = [];

    /**
     * @param mixed $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MigrafoldServiceProvider::class];
    }

    /** @param mixed $app */
    protected function defineEnvironment($app): void
    {
        if (! $app instanceof Application) {
            throw new LogicException('The Testbench application has not been created.');
        }

        $database = getenv('DB_DATABASE');
        $config = $app->make('config');

        if (! $config instanceof ConfigRepository) {
            throw new RuntimeException('Laravel configuration repository is unavailable.');
        }

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => is_string($database) && $database !== '' ? $database : ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()->make('config');

        if (! $config instanceof ConfigRepository) {
            throw new RuntimeException('Laravel configuration repository is unavailable.');
        }

        $database = $config->get('database.connections.testing.database');

        if (! is_string($database) || ($database !== ':memory:' && ! str_ends_with($database, '_testing'))) {
            throw new RuntimeException('Lifecycle probes may only reset an in-memory or *_testing database.');
        }

        $manager = $this->application()->make('db');

        if (! $manager instanceof DatabaseManager) {
            throw new RuntimeException('Laravel database manager is unavailable.');
        }

        $manager->connection('testing')->getSchemaBuilder()->dropAllTables();
        $this->migrator()->getRepository()->createRepository();
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureDirectories as $directory) {
            $files = glob($directory.'/*.php');

            if ($files === false) {
                throw new RuntimeException("Unable to inspect migration fixture directory [{$directory}].");
            }

            foreach ($files as $file) {
                unlink($file);
            }

            rmdir($directory);
        }

        parent::tearDown();
    }

    protected function migrator(): Migrator
    {
        $migrator = $this->application()->make('migrator');

        if (! $migrator instanceof Migrator) {
            throw new RuntimeException('Laravel migrator is unavailable.');
        }

        return $migrator;
    }

    protected function connection(): Connection
    {
        $manager = $this->application()->make('db');

        if (! $manager instanceof DatabaseManager) {
            throw new RuntimeException('Laravel database manager is unavailable.');
        }

        return $manager->connection('testing');
    }

    private function application(): Application
    {
        if (! $this->app instanceof Application) {
            throw new LogicException('The Testbench application has not been created.');
        }

        return $this->app;
    }

    /**
     * @param array<string, string> $migrations
     */
    protected function migrationDirectory(array $migrations): string
    {
        $directory = sys_get_temp_dir().'/migrafold-lifecycle-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700, true)) {
            throw new RuntimeException("Unable to create migration fixture directory [{$directory}].");
        }

        $this->fixtureDirectories[] = $directory;

        foreach ($migrations as $name => $contents) {
            $written = file_put_contents($directory.'/'.$name.'.php', $contents);

            if ($written === false) {
                throw new RuntimeException("Unable to write migration fixture [{$name}].");
            }
        }

        return $directory;
    }
}
