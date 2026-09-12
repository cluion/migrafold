<?php

declare(strict_types=1);

namespace Tests\Integration\Replay;

use Cluion\Migrafold\Discovery\LaravelMigrationSourceAdapter;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Discovery\MigrationDiscoverer;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Planning\CompactionPlanner;
use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Cluion\Migrafold\Replay\PostgresReplaySandboxManager;
use Cluion\Migrafold\Replay\PostgresReplayVerifier;
use Cluion\Migrafold\Replay\ReplayVerifierResolver;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\PostgresConnection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PostgresReplayVerifierTest extends TestCase
{
    private Application $application;

    private DatabaseManager $databases;

    private PostgresConnection $connection;

    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('MIGRAFOLD_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Real database tests require MIGRAFOLD_DATABASE_TESTS=1.');
        }

        if (getenv('MIGRAFOLD_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL replay tests require MIGRAFOLD_DB_DRIVER=pgsql.');
        }

        $database = $this->environment('MIGRAFOLD_DB_DATABASE');

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException('Real database tests may only use a *_testing database.');
        }

        $this->application = new Application();
        $this->application->instance('config', new ConfigRepository([
            'database' => [
                'default' => 'testing',
                'connections' => [
                    'testing' => [
                        'driver' => 'pgsql',
                        'host' => $this->environment('MIGRAFOLD_DB_HOST'),
                        'port' => $this->environment('MIGRAFOLD_DB_PORT'),
                        'database' => $database,
                        'username' => $this->environment('MIGRAFOLD_DB_USERNAME'),
                        'password' => $this->environment('MIGRAFOLD_DB_PASSWORD'),
                        'charset' => 'utf8',
                        'prefix' => '',
                        'search_path' => 'public',
                    ],
                ],
            ],
        ]));
        $this->databases = new DatabaseManager(
            $this->application,
            new ConnectionFactory($this->application),
        );
        $this->application->instance('db', $this->databases);
        $connection = $this->databases->connection('testing');

        if (! $connection instanceof PostgresConnection) {
            throw new RuntimeException('Real database connection did not resolve to PostgreSQL.');
        }

        $this->connection = $connection;
        $this->application->bind(
            'db.schema',
            fn (): mixed => $this->databases->connection()->getSchemaBuilder(),
        );
        Facade::setFacadeApplication($this->application);
        $this->resetSourceDatabase();

        if ($this->sandboxDatabases() !== []) {
            throw new RuntimeException('Real database server contains an unexpected Migrafold replay sandbox.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->resetSourceDatabase();
        }

        if (isset($this->databases)) {
            $this->databases->purge('testing');
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        foreach (array_reverse($this->roots) as $root) {
            $this->removeDirectory($root);
        }

        parent::tearDown();
    }

    public function test_it_replays_and_plans_in_isolated_postgres_databases(): void
    {
        [$root, $catalog] = $this->sourceCatalog();
        $this->runSources($catalog);
        $temporaryRoot = $this->root('artifacts');
        $files = new Filesystem();
        $verifier = new PostgresReplayVerifier(
            $this->databases,
            $files,
            $this->connection,
            temporaryRoot: $temporaryRoot,
        );
        $defaultConnection = $this->databases->getDefaultConnection();
        $result = $verifier->verify($catalog, '2026_09_12');

        self::assertSame('pgsql', $result->source->driver);
        self::assertSame($result->source->toJson(), $result->baseline->toJson());
        self::assertSame(3, $result->sourceMigrations);
        self::assertSame(1, $result->baselineMigrations);
        self::assertSame(1, $result->preservedMigrations);
        self::assertSame($defaultConnection, $this->databases->getDefaultConnection());
        self::assertTrue($this->connection->getSchemaBuilder()->hasTable('users'));
        self::assertSame([], $this->sandboxDatabases());
        $this->assertDirectoryIsEmpty($temporaryRoot);

        $plan = (new CompactionPlanner(new ReplayVerifierResolver($this->databases, $files)))->plan(
            $root,
            $this->connection,
            [new LaravelMigrationSourceAdapter($root)],
            '2026_09_12',
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
        );

        self::assertSame([
            '2020_01_01_000000_create_users_table',
            '2020_01_02_000000_add_nickname_to_users_table',
        ], $plan->activation->retiredNames());
        self::assertSame(
            ['2030_01_01_000000_seed_system_user'],
            $plan->summary()['analysis']['preserve'],
        );
        self::assertSame('pgsql', $plan->snapshot->driver);
        self::assertSame($result->source->toJson(), $plan->snapshot->toJson());
        self::assertSame([], $this->sandboxDatabases());
        $this->assertDirectoryIsEmpty($temporaryRoot);
    }

    public function test_cleanup_refuses_a_tampered_ownership_marker(): void
    {
        $manager = new PostgresReplaySandboxManager($this->databases, $this->connection);
        $sandbox = $manager->create('source');
        $marker = $this->quoteIdentifier(PostgresReplaySandboxManager::MARKER_TABLE);
        $sandbox->connection->update(
            "update {$marker} set token = ?",
            ['tampered'],
        );

        try {
            $manager->destroy($sandbox);
            self::fail('Tampered sandbox ownership unexpectedly allowed cleanup.');
        } catch (ReplayVerificationFailed $exception) {
            self::assertStringContainsString('ownership marker does not match', $exception->getMessage());
        }

        self::assertContains($sandbox->database, $this->sandboxDatabases());
        $sandbox->connection->update(
            "update {$marker} set token = ?",
            [$sandbox->token],
        );
        $manager->destroy($sandbox);
        self::assertSame([], $this->sandboxDatabases());
    }

    public function test_sandbox_creation_is_refused_inside_a_source_transaction(): void
    {
        $this->connection->beginTransaction();

        try {
            $this->expectException(ReplayVerificationFailed::class);
            $this->expectExceptionMessage('active transaction');

            (new PostgresReplaySandboxManager($this->databases, $this->connection))->create('source');
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return array{0: string, 1: MigrationCatalog} */
    private function sourceCatalog(): array
    {
        $root = $this->root('postgres-project');
        $directory = $root.'/database/migrations';
        $this->write($directory.'/2020_01_01_000000_create_users_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });
    }

    public function down(): void {}
};
PHP);
        $this->write($directory.'/2020_01_02_000000_add_nickname_to_users_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->string('nickname')->nullable();
        });
    }

    public function down(): void {}
};
PHP);
        $this->write($directory.'/2030_01_01_000000_seed_system_user.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->insert(['email' => 'system@example.test']);
    }

    public function down(): void {}
};
PHP);

        return [
            $root,
            (new MigrationDiscoverer())->discover([new LaravelMigrationSourceAdapter($root)]),
        ];
    }

    private function runSources(MigrationCatalog $catalog): void
    {
        $repository = new DatabaseMigrationRepository($this->databases, 'migrations');
        $repository->createRepository();
        $migrator = new Migrator($repository, $this->databases, new Filesystem());
        $ran = $migrator->run(array_map(
            static fn ($migration): string => $migration->absolutePath,
            $catalog->migrations,
        ));

        self::assertCount(count($catalog->migrations), $ran);
    }

    /** @return list<string> */
    private function sandboxDatabases(): array
    {
        $databases = [];

        foreach ($this->connection->select(
            'select datname as database_name from pg_database order by datname',
        ) as $row) {
            $metadata = array_change_key_case((array) $row, CASE_LOWER);
            $database = $metadata['database_name'] ?? null;

            if (is_string($database)
                && str_starts_with($database, PostgresReplaySandboxManager::DATABASE_PREFIX)) {
                $databases[] = $database;
            }
        }

        return $databases;
    }

    private function resetSourceDatabase(): void
    {
        $this->connection->unprepared(<<<'SQL'
drop schema if exists public cascade;
create schema public
SQL);
    }

    private function root(string $label): string
    {
        $root = sys_get_temp_dir().'/migrafold-'.$label.'-'.bin2hex(random_bytes(8));

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

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            self::fail("Unable to create migration directory [{$directory}].");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("Unable to write migration fixture [{$path}].");
        }
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

    private function assertDirectoryIsEmpty(string $directory): void
    {
        $entries = scandir($directory);

        if ($entries === false) {
            self::fail("Unable to inspect temporary directory [{$directory}].");
        }

        self::assertSame(['.', '..'], $entries);
    }

    private function environment(string $name): string
    {
        $value = getenv($name);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Required environment variable [{$name}] is missing.");
        }

        return $value;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier) !== 1) {
            throw new RuntimeException("Unsafe test database identifier [{$identifier}].");
        }

        return '"'.$identifier.'"';
    }
}
