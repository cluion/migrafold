<?php

declare(strict_types=1);

namespace MigrafoldInterop\Nwidart;

use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Execution\CompactionExecutor;
use Cluion\Migrafold\MigrafoldServiceProvider;
use Cluion\Migrafold\Planning\CompactionPlanner;
use Cluion\Migrafold\Planning\MigrationSourceAdapterFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\LaravelModulesServiceProvider;
use Nwidart\Modules\Module;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class NwidartInteropTest extends TestCase
{
    private ?string $fixtureRoot = null;

    /**
     * @param mixed $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelModulesServiceProvider::class,
            MigrafoldServiceProvider::class,
        ];
    }

    /** @param mixed $app */
    protected function defineEnvironment($app): void
    {
        if (! $app instanceof Application) {
            throw new RuntimeException('The interoperability application is unavailable.');
        }

        $root = sys_get_temp_dir().'/migrafold-nwidart-interop-'.bin2hex(random_bytes(8));

        if (! mkdir($root, 0700, true)) {
            throw new RuntimeException("Unable to create interoperability root [{$root}].");
        }

        $resolved = realpath($root);

        if ($resolved === false) {
            throw new RuntimeException("Unable to resolve interoperability root [{$root}].");
        }

        $this->fixtureRoot = $resolved;
        $this->write(
            $resolved.'/Modules/Billing/module.json',
            json_encode([
                'name' => 'Billing',
                'alias' => 'billing',
                'description' => 'Migrafold nWidart interoperability fixture',
                'keywords' => [],
                'priority' => 0,
                'order' => 0,
                'providers' => [],
                'aliases' => [],
                'files' => [],
                'requires' => [],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
        );
        $this->write(
            $resolved.'/Modules/Billing/database/migrations/2020_01_01_000000_create_invoices_table.php',
            <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', static function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
PHP,
        );
        $this->write(
            $resolved.'/modules_statuses.json',
            json_encode(['Billing' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
        );

        $config = $app->make('config');

        if (! $config instanceof ConfigRepository) {
            throw new RuntimeException('Laravel configuration is unavailable.');
        }

        $config->set('database.default', 'testing');
        $database = getenv('DB_DATABASE');

        if (! is_string($database) || ! str_ends_with($database, '_testing')) {
            throw new RuntimeException('Interoperability tests require a dedicated _testing database.');
        }

        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'url' => null,
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $config->set('modules.paths.modules', $resolved.'/Modules');
        $config->set('modules.paths.generator.migration.path', 'database/migrations');
        $config->set('modules.activators.file.statuses-file', $resolved.'/modules_statuses.json');
        $config->set('modules.activator', 'file');
        $config->set('modules.scan.enabled', false);
        $config->set('migrafold.nwidart.table_owners', [
            'invoices' => 'Billing',
        ]);

        // nWidart resolves its activator while the provider is registering,
        // before Testbench applies this test's environment overrides.
        $app->forgetInstance(ActivatorInterface::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $connection = $this->connection();

        if (! str_ends_with($connection->getDatabaseName(), '_testing')) {
            throw new RuntimeException('Interoperability tests require a dedicated _testing database.');
        }

        $schema = $connection->getSchemaBuilder();
        $schema->dropAllTables();
        $schema->create('migrations', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $schema->create('invoices', static function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
        });
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app instanceof Application) {
                $this->connection()->getSchemaBuilder()->dropAllTables();
            }

            parent::tearDown();
        } finally {
            if ($this->fixtureRoot !== null) {
                $this->removeDirectory($this->fixtureRoot);
                $this->fixtureRoot = null;
            }
        }
    }

    public function test_real_nwidart_runtime_plans_and_archives_module_migrations(): void
    {
        $root = $this->root();
        $repository = $this->app?->make(RepositoryInterface::class);

        self::assertInstanceOf(RepositoryInterface::class, $repository);
        $enabled = $repository->allEnabled();
        self::assertCount(1, $enabled, json_encode([
            'repository_path' => $repository->getPath(),
            'all_modules' => array_keys($repository->all()),
            'status_file' => $this->application()->make('config')->get('modules.activators.file.statuses-file'),
        ], JSON_THROW_ON_ERROR));
        $module = array_values($enabled)[0];
        self::assertInstanceOf(Module::class, $module);
        self::assertSame('Billing', $module->getName());
        self::assertSame($root.'/Modules/Billing', $module->getPath());

        $adapters = (new MigrationSourceAdapterFactory())->forApplication($root, $this->application());
        $plan = $this->application()->make(CompactionPlanner::class)->plan(
            $root,
            $this->connection(),
            $adapters,
            '2026_09_12',
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
        );
        $source = $root.'/Modules/Billing/database/migrations/2020_01_01_000000_create_invoices_table.php';
        $baselineName = $plan->activation->baselineNames()[0];
        $archive = $plan->disposition->items[0]->destination;

        self::assertCount(2, $adapters);
        self::assertSame('nwidart:Billing', $plan->output->owners[0]->ownerId);
        self::assertSame(dirname($source), $plan->output->owners[0]->output->directory);
        self::assertNotNull($archive);
        $this->connection()->table('migrations')->insert([
            'migration' => '2020_01_01_000000_create_invoices_table',
            'batch' => 1,
        ]);

        $result = (new CompactionExecutor())->execute($plan, $this->connection());

        self::assertTrue($result->clean());
        self::assertFileDoesNotExist($source);
        self::assertFileExists($archive);
        self::assertFileExists(dirname($source).'/'.$baselineName.'.php');
        self::assertFileExists(dirname($source).'/.migrafold-manifest.json');
        self::assertSame(
            [$baselineName],
            $this->connection()->table('migrations')->pluck('migration')->all(),
        );
    }

    private function connection(): Connection
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
            throw new RuntimeException('Laravel application is unavailable.');
        }

        return $this->app;
    }

    private function root(): string
    {
        if ($this->fixtureRoot === null) {
            throw new RuntimeException('Interoperability fixture root is unavailable.');
        }

        return $this->fixtureRoot;
    }

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            throw new RuntimeException("Unable to create interoperability directory [{$directory}].");
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to write interoperability fixture [{$path}].");
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            throw new RuntimeException("Unable to inspect interoperability directory [{$directory}].");
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
