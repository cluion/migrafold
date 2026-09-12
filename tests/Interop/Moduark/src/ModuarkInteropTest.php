<?php

declare(strict_types=1);

namespace MigrafoldInterop\Moduark;

use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Execution\CompactionExecutor;
use Cluion\Migrafold\MigrafoldServiceProvider;
use Cluion\Migrafold\Planning\CompactionPlanner;
use Cluion\Migrafold\Planning\MigrationSourceAdapterFactory;
use Cluion\Migrafold\Verification\Exception\ManifestVerificationFailed;
use Cluion\Migrafold\Verification\InstalledCompactionVerifier;
use Cluion\Moduark\ModuarkServiceProvider;
use Cluion\Moduark\Persistence\TableOwnershipIndex;
use Cluion\Moduark\Registry\ModuleRegistry;
use Cluion\Moduark\Resources\ResourceManifest;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use RuntimeException;

final class ModuarkInteropTest extends TestCase
{
    private const MODULE_CLASS = 'MigrafoldInteropFixture\\Billing\\BillingModule';

    private static ?string $applicationRoot = null;

    private ?string $fixtureRoot = null;

    private ?Closure $moduleAutoloader = null;

    public static function applicationBasePath(): string
    {
        if (self::$applicationRoot === null) {
            throw new RuntimeException('The Moduark interoperability application root is unavailable.');
        }

        return self::$applicationRoot;
    }

    /**
     * @param mixed $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ModuarkServiceProvider::class,
            MigrafoldServiceProvider::class,
        ];
    }

    /** @param mixed $app */
    protected function defineEnvironment($app): void
    {
        if (! $app instanceof Application) {
            throw new RuntimeException('The interoperability application is unavailable.');
        }

        $config = $app->make('config');

        if (! $config instanceof ConfigRepository) {
            throw new RuntimeException('Laravel configuration is unavailable.');
        }

        $database = getenv('DB_DATABASE');

        if (! is_string($database) || ! str_ends_with($database, '_testing')) {
            throw new RuntimeException('Interoperability tests require a dedicated _testing database.');
        }

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'url' => null,
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function setUp(): void
    {
        $this->createFixture();

        try {
            parent::setUp();
        } catch (\Throwable $exception) {
            $this->destroyFixture();

            throw $exception;
        }

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
            $this->destroyFixture();
        }
    }

    public function test_real_moduark_runtime_plans_and_archives_module_migrations(): void
    {
        $root = $this->root();
        $moduleDirectory = $root.'/app/Modules/Billing/Database/Migrations';
        $source = $moduleDirectory.'/2020_01_01_000000_create_invoices_table.php';
        $preservedName = '2030_01_01_000000_seed_invoice';
        $preservedSource = $moduleDirectory.'/'.$preservedName.'.php';
        $registry = $this->application()->make(ModuleRegistry::class);
        $resources = $this->application()->make(ResourceManifest::class);
        $ownership = $this->application()->make(TableOwnershipIndex::class);

        self::assertSame([self::MODULE_CLASS], $registry->moduleClasses());
        self::assertSame(self::MODULE_CLASS, $ownership->owner('invoices'));
        self::assertSame(
            [$moduleDirectory],
            array_column(
                array_values(array_filter(
                    $resources->toArray()['resources'],
                    static fn (array $resource): bool => $resource['plugin'] === 'migrations',
                )),
                'source',
            ),
        );

        $adapters = (new MigrationSourceAdapterFactory())->forApplication($root, $this->application());
        $plan = $this->application()->make(CompactionPlanner::class)->plan(
            $root,
            $this->connection(),
            $adapters,
            '2026_09_12',
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
        );
        $baselineName = $plan->activation->baselineNames()[0];
        $archive = $plan->disposition->items[0]->destination;
        $manifestPath = $moduleDirectory.'/.migrafold-manifest.json';

        self::assertCount(2, $adapters);
        self::assertSame('moduark:Billing', $plan->output->owners[0]->ownerId);
        self::assertSame($moduleDirectory, $plan->output->owners[0]->output->directory);
        self::assertNotNull($archive);
        $this->connection()->table('migrations')->insert([
            [
                'migration' => '2020_01_01_000000_create_invoices_table',
                'batch' => 1,
            ],
            [
                'migration' => $preservedName,
                'batch' => 2,
            ],
        ]);

        $result = (new CompactionExecutor())->execute($plan, $this->connection());

        self::assertTrue($result->clean());
        self::assertFileDoesNotExist($source);
        self::assertFileExists($archive);
        self::assertFileExists($preservedSource);
        self::assertFileExists($moduleDirectory.'/'.$baselineName.'.php');
        self::assertFileExists($manifestPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame('migrafold-manifest-v2', $manifest['format_version']);
        self::assertSame('moduark:Billing', $manifest['migration_scope']['compacted'][0]['owner']);
        self::assertSame([$preservedName], array_column($manifest['migration_scope']['preserved'], 'name'));
        self::assertSame('sqlite', $manifest['verification']['driver']);
        self::assertSame(
            [$preservedName, $baselineName],
            $this->connection()->table('migrations')->pluck('migration')->all(),
        );
        $verification = (new InstalledCompactionVerifier())->verify(
            $root,
            $this->connection(),
            $adapters,
        );
        self::assertSame('sqlite', $verification->driver);
        self::assertSame([$baselineName], $verification->baselines);
        self::assertSame(['2020_01_01_000000_create_invoices_table'], $verification->compacted);
        self::assertSame([$preservedName], $verification->preserved);
        self::assertSame([$preservedName], $verification->preservedRecorded);
        self::assertSame([], $verification->preservedPending);
        self::assertSame(3, $verification->baselineBatch);
        self::assertSame([], $verification->untrackedMigrations);
        $verifiedPaths = [
            $moduleDirectory.'/'.$baselineName.'.php',
            $manifestPath,
            $preservedSource,
        ];
        $hashesBeforeCommand = array_map('hash_file', array_fill(0, 3, 'sha256'), $verifiedPaths);
        $recordsBeforeCommand = array_map(
            static fn (object $record): array => (array) $record,
            $this->connection()->table('migrations')->orderBy('id')->get()->all(),
        );
        $kernel = $this->application()->make(Kernel::class);
        $status = $kernel->call('migrafold:verify', [
            '--connection' => 'testing',
            '--json' => true,
        ]);
        $commandOutput = $kernel->output();
        self::assertSame(0, $status);
        self::assertStringContainsString('"verified": true', $commandOutput);
        self::assertStringContainsString('"preserved_recorded"', $commandOutput);
        self::assertSame(
            $hashesBeforeCommand,
            array_map('hash_file', array_fill(0, 3, 'sha256'), $verifiedPaths),
        );
        self::assertSame(
            $recordsBeforeCommand,
            array_map(
                static fn (object $record): array => (array) $record,
                $this->connection()->table('migrations')->orderBy('id')->get()->all(),
            ),
        );

        self::assertSame(
            1,
            $this->connection()->table('migrations')->where('migration', $preservedName)->delete(),
        );
        $pendingVerification = (new InstalledCompactionVerifier())->verify(
            $root,
            $this->connection(),
            $adapters,
        );
        self::assertSame([], $pendingVerification->preservedRecorded);
        self::assertSame([$preservedName], $pendingVerification->preservedPending);
        self::assertSame(
            0,
            $this->connection()->table('migrations')->where('migration', $preservedName)->count(),
        );
        self::assertTrue($this->connection()->table('migrations')->insert([
            'migration' => $preservedName,
            'batch' => 2,
        ]));

        $baselinePath = $moduleDirectory.'/'.$baselineName.'.php';
        $baselineContents = file_get_contents($baselinePath);
        self::assertIsString($baselineContents);
        self::assertNotFalse(file_put_contents($baselinePath, $baselineContents."\n// tampered\n"));
        $records = $this->connection()->table('migrations')->orderBy('id')->pluck('migration')->all();
        $failedStatus = $kernel->call('migrafold:verify', [
            '--connection' => 'testing',
        ]);
        $failedOutput = $kernel->output();
        self::assertSame(1, $failedStatus);
        self::assertStringContainsString('does not match its manifest fingerprint', $failedOutput);

        try {
            (new InstalledCompactionVerifier())->verify($root, $this->connection(), $adapters);
            self::fail('Expected a tampered baseline to fail verification.');
        } catch (ManifestVerificationFailed $exception) {
            self::assertStringContainsString('does not match its manifest fingerprint', $exception->getMessage());
        }

        self::assertSame($records, $this->connection()->table('migrations')->orderBy('id')->pluck('migration')->all());
        self::assertTrue($this->connection()->getSchemaBuilder()->hasTable('invoices'));
        self::assertNotFalse(file_put_contents($baselinePath, $baselineContents));
        self::assertSame(1, $this->connection()->table('migrations')->where('migration', $baselineName)->delete());

        try {
            (new InstalledCompactionVerifier())->verify($root, $this->connection(), $adapters);
            self::fail('Expected a missing baseline record to fail verification.');
        } catch (ManifestVerificationFailed $exception) {
            self::assertStringContainsString('is missing or duplicated', $exception->getMessage());
        }

        self::assertSame(0, $this->connection()->table('migrations')->where('migration', $baselineName)->count());
        self::assertTrue($this->connection()->table('migrations')->insert([
            'migration' => $baselineName,
            'batch' => 3,
        ]));
        $this->connection()->getSchemaBuilder()->table('invoices', static function (Blueprint $table): void {
            $table->string('drift')->nullable();
        });

        try {
            (new InstalledCompactionVerifier())->verify($root, $this->connection(), $adapters);
            self::fail('Expected schema drift to fail verification.');
        } catch (ManifestVerificationFailed $exception) {
            self::assertStringContainsString('schema does not match', $exception->getMessage());
        }
    }

    private function createFixture(): void
    {
        $root = sys_get_temp_dir().'/migrafold-moduark-interop-'.bin2hex(random_bytes(8));

        if (! mkdir($root.'/bootstrap/cache', 0700, true)) {
            throw new RuntimeException("Unable to create interoperability root [{$root}].");
        }

        $resolved = realpath($root);

        if ($resolved === false) {
            throw new RuntimeException("Unable to resolve interoperability root [{$root}].");
        }

        $this->fixtureRoot = $resolved;
        self::$applicationRoot = $resolved;
        $moduleFile = $resolved.'/app/Modules/Billing/BillingModule.php';
        $this->write($moduleFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace MigrafoldInteropFixture\Billing;

use Cluion\Moduark\Module;

final class BillingModule extends Module
{
    /** @return list<string> */
    public function tables(): array
    {
        return ['invoices'];
    }
}
PHP
        );
        $this->write(
            $resolved.'/app/Modules/Billing/Database/Migrations/2020_01_01_000000_create_invoices_table.php',
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
            $resolved.'/app/Modules/Billing/Database/Migrations/2030_01_01_000000_seed_invoice.php',
            <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoices')->insert(['number' => 'SYSTEM']);
    }

    public function down(): void {}
};
PHP,
        );

        $this->moduleAutoloader = static function (string $class) use ($moduleFile): void {
            if ($class === self::MODULE_CLASS) {
                require $moduleFile;
            }
        };
        spl_autoload_register($this->moduleAutoloader, true, true);
    }

    private function destroyFixture(): void
    {
        if ($this->moduleAutoloader !== null) {
            spl_autoload_unregister($this->moduleAutoloader);
            $this->moduleAutoloader = null;
        }

        if ($this->fixtureRoot !== null) {
            $this->removeDirectory($this->fixtureRoot);
            $this->fixtureRoot = null;
        }

        self::$applicationRoot = null;
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
