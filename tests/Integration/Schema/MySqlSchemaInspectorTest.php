<?php

declare(strict_types=1);

namespace Tests\Integration\Schema;

use Cluion\Migrafold\Activation\DatabaseMigrationRecordTransaction;
use Cluion\Migrafold\Migration\BaselineMigrationGenerator;
use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Schema\Exception\UnsupportedSchemaFeature;
use Cluion\Migrafold\Schema\MySqlSchemaInspector;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MySqlSchemaInspectorTest extends TestCase
{
    private MySqlConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('MIGRAFOLD_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Real database tests require MIGRAFOLD_DATABASE_TESTS=1.');
        }

        $driver = $this->environment('MIGRAFOLD_DB_DRIVER');
        $database = $this->environment('MIGRAFOLD_DB_DATABASE');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('MIGRAFOLD_DB_DRIVER must be mysql or mariadb.');
        }

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException('Real database tests may only use a *_testing database.');
        }

        $host = $this->environment('MIGRAFOLD_DB_HOST');
        $port = $this->environment('MIGRAFOLD_DB_PORT');
        $username = $this->environment('MIGRAFOLD_DB_USERNAME');
        $password = $this->environment('MIGRAFOLD_DB_PASSWORD');
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $config = [
            'driver' => $driver,
            'database' => $database,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ];

        $this->connection = $driver === 'mariadb'
            ? new MariaDbConnection($pdo, $database, '', $config)
            : new MySqlConnection($pdo, $database, '', $config);

        $container = new Application();
        $container->instance('db', $this->connection);
        $container->instance('db.schema', $this->connection->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->resetDatabase();
            $this->connection->disconnect();
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_it_builds_a_canonical_snapshot_on_a_real_server(): void
    {
        $this->createSupportedSchema();

        $inspector = new MySqlSchemaInspector();
        $snapshot = $inspector->inspect($this->connection);
        $again = $inspector->inspect($this->connection);

        self::assertSame($this->environment('MIGRAFOLD_DB_DRIVER'), $snapshot->driver);
        self::assertSame(['roles', 'users'], array_column($snapshot->toArray()['tables'], 'name'));
        self::assertSame($snapshot->toJson(), $again->toJson());
        self::assertSame($snapshot->fingerprint(), $again->fingerprint());
        self::assertSame([
            'mysql' => '6b494028c4cae3c76b90629d4e974c9afb4b75382bfe28efcc165d62eb8b0562',
            'mariadb' => '3bd3277862124cc1b0c1a7e32afdbc2c0c68b8803927b3619be03e47f188a606',
        ][$snapshot->driver], $snapshot->fingerprint());
        self::assertContains('named_foreign_keys', $snapshot->capabilities->supported);
        self::assertContains('cross_schema_foreign_keys', $snapshot->capabilities->unsupported);
        self::assertContains('index_prefix_lengths', $snapshot->capabilities->unsupported);

        $users = $snapshot->tables[1];

        self::assertNull($users->schema);
        self::assertSame('InnoDB', $users->engine);
        self::assertSame('Application users', $users->comment);
        self::assertSame(
            ['id', 'role_id', 'email', 'nickname', 'status', 'normalized_email', 'created_at'],
            array_column($users->toArray()['columns'], 'name'),
        );
        self::assertSame('Login address', $users->columns[2]->comment);
        $generation = $users->columns[5]->generation;
        self::assertNotNull($generation);
        self::assertSame('virtual', $generation->type);
        self::assertNotSame('', $generation->expression);
        self::assertSame(
            ['primary', 'users_email_unique', 'users_lookup_index', 'users_role_id_foreign', 'users_search_fulltext'],
            array_column($users->toArray()['indexes'], 'name'),
        );
        self::assertSame('fulltext', $users->indexes[4]->type);
        self::assertSame('users_role_id_foreign', $users->foreignKeys[0]->name);
        self::assertNull($users->foreignKeys[0]->foreignSchema);
        self::assertSame('cascade', $users->foreignKeys[0]->onUpdate);
        self::assertSame('restrict', $users->foreignKeys[0]->onDelete);
    }

    public function test_generated_baselines_replay_the_same_schema_on_a_real_server(): void
    {
        $this->createSupportedSchema();

        $inspector = new MySqlSchemaInspector();
        $source = $inspector->inspect($this->connection);
        $generated = (new BaselineMigrationGenerator())->generate($source, '2026_09_11');

        $this->resetDatabase();

        foreach ($generated as $migration) {
            $this->runMigration($migration);
        }

        self::assertSame($source->toJson(), $inspector->inspect($this->connection)->toJson());
    }

    public function test_boolean_columns_round_trip_without_losing_mariadb_display_width(): void
    {
        $this->connection->statement(
            'create table feature_flags (id bigint unsigned not null auto_increment primary key, enabled boolean not null default true)',
        );

        $inspector = new MySqlSchemaInspector();
        $source = $inspector->inspect($this->connection);
        $generated = (new BaselineMigrationGenerator())->generate($source, '2026_09_11');

        self::assertStringContainsString("\$table->boolean('enabled')->default(1);", $generated[0]->contents);

        $this->resetDatabase();
        $this->runMigration($generated[0]);

        self::assertSame($source->toJson(), $inspector->inspect($this->connection)->toJson());
    }

    public function test_unsigned_integer_columns_round_trip_without_losing_mariadb_display_widths(): void
    {
        $this->connection->statement(<<<'SQL'
create table unsigned_integer_widths (
    id bigint unsigned not null auto_increment primary key,
    tiny_value tinyint unsigned not null,
    small_value smallint unsigned not null,
    medium_value mediumint unsigned not null,
    integer_value int unsigned not null,
    big_value bigint unsigned not null
)
SQL);

        $inspector = new MySqlSchemaInspector();
        $source = $inspector->inspect($this->connection);
        $generated = (new BaselineMigrationGenerator())->generate($source, '2026_09_12');

        self::assertStringContainsString("\$table->unsignedTinyInteger('tiny_value');", $generated[0]->contents);
        self::assertStringContainsString("\$table->unsignedSmallInteger('small_value');", $generated[0]->contents);
        self::assertStringContainsString("\$table->unsignedMediumInteger('medium_value');", $generated[0]->contents);
        self::assertStringContainsString("\$table->unsignedInteger('integer_value');", $generated[0]->contents);
        self::assertStringContainsString("\$table->unsignedBigInteger('big_value');", $generated[0]->contents);

        $this->resetDatabase();
        $this->runMigration($generated[0]);

        self::assertSame($source->toJson(), $inspector->inspect($this->connection)->toJson());
    }

    public function test_native_mariadb_uuid_columns_round_trip(): void
    {
        if ($this->connection->getDriverName() !== 'mariadb') {
            self::markTestSkipped('Native UUID columns are specific to MariaDB.');
        }

        $this->connection->statement('create table public_ids (id uuid not null primary key)');

        $inspector = new MySqlSchemaInspector();
        $source = $inspector->inspect($this->connection);
        $generated = (new BaselineMigrationGenerator())->generate($source, '2026_09_12');

        self::assertStringContainsString("\$table->uuid('id');", $generated[0]->contents);

        $this->resetDatabase();
        $this->runMigration($generated[0]);

        self::assertSame($source->toJson(), $inspector->inspect($this->connection)->toJson());
    }

    public function test_common_numeric_and_binary_columns_round_trip_on_a_real_server(): void
    {
        $this->connection->statement(<<<'SQL'
create table measurements (
    id bigint unsigned not null auto_increment primary key,
    ratio float unsigned not null default 1.25,
    score double not null default 2.5,
    payload blob not null,
    fixed_token binary(16) not null,
    variable_token varbinary(32) not null
)
SQL);

        $inspector = new MySqlSchemaInspector();
        $source = $inspector->inspect($this->connection);
        $generated = (new BaselineMigrationGenerator())->generate($source, '2026_09_12');

        self::assertStringContainsString("\$table->float('ratio', 24)->unsigned()->default(1.25);", $generated[0]->contents);
        self::assertStringContainsString("\$table->double('score')->default(2.5);", $generated[0]->contents);
        self::assertStringContainsString("\$table->binary('payload');", $generated[0]->contents);
        self::assertStringContainsString("\$table->binary('fixed_token', 16, true);", $generated[0]->contents);
        self::assertStringContainsString("\$table->binary('variable_token', 32);", $generated[0]->contents);

        $this->resetDatabase();
        $this->runMigration($generated[0]);

        self::assertSame($source->toJson(), $inspector->inspect($this->connection)->toJson());
    }

    public function test_migration_record_transaction_commits_and_releases_its_lock(): void
    {
        $this->createMigrationRepository();
        $transaction = new DatabaseMigrationRecordTransaction(1);

        $first = $transaction->run(
            $this->connection,
            'integration:migrations',
            function (): string {
                $this->connection->table('migrations')->insert([
                    'migration' => '2026_09_12_000001_create_users_baseline',
                    'batch' => 1,
                ]);

                return 'committed';
            },
        );
        $second = $transaction->run(
            $this->connection,
            'integration:migrations',
            fn (): int => $this->connection->table('migrations')->count(),
        );

        self::assertSame('committed', $first);
        self::assertSame(1, $second);
        self::assertSame(0, $this->connection->transactionLevel());
    }

    public function test_migration_record_transaction_rolls_back_and_releases_its_lock(): void
    {
        $this->createMigrationRepository();
        $transaction = new DatabaseMigrationRecordTransaction(1);

        try {
            $transaction->run(
                $this->connection,
                'integration:migrations',
                function (): void {
                    $this->connection->table('migrations')->insert([
                        'migration' => '2026_09_12_000001_create_users_baseline',
                        'batch' => 1,
                    ]);

                    throw new RuntimeException('simulated transaction failure');
                },
            );
        } catch (RuntimeException $exception) {
            self::assertSame('simulated transaction failure', $exception->getMessage());
        }

        $count = $transaction->run(
            $this->connection,
            'integration:migrations',
            fn (): int => $this->connection->table('migrations')->count(),
        );

        self::assertSame(0, $count);
        self::assertSame(0, $this->connection->transactionLevel());
    }

    private function createSupportedSchema(): void
    {
        $this->connection->unprepared(<<<'SQL'
create table roles (
    id bigint unsigned not null auto_increment,
    code varchar(32) collate utf8mb4_bin not null,
    primary key (id),
    unique key roles_code_unique (code)
) engine = InnoDB default character set utf8mb4 collate utf8mb4_unicode_ci comment = 'Role catalog'
SQL);
        $this->connection->unprepared(<<<'SQL'
create table users (
    id bigint unsigned not null auto_increment,
    role_id bigint unsigned not null,
    email varchar(255) collate utf8mb4_bin not null comment 'Login address',
    nickname varchar(80) null default 'guest',
    status enum('active', 'disabled') not null default 'active',
    normalized_email varchar(255) generated always as (lower(`email`)) virtual,
    created_at timestamp null default current_timestamp,
    primary key (id),
    unique key users_email_unique (email),
    key users_lookup_index (nickname, email),
    fulltext key users_search_fulltext (email),
    constraint users_role_id_foreign foreign key (role_id) references roles (id)
        on update cascade on delete restrict
) engine = InnoDB default character set utf8mb4 collate utf8mb4_unicode_ci comment = 'Application users'
SQL);
    }

    private function createMigrationRepository(): void
    {
        $this->connection->getSchemaBuilder()->create('migrations', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
    }

    public function test_exclusions_are_case_insensitive(): void
    {
        $this->connection->statement('create table audit_events (id bigint unsigned not null primary key)');
        $this->connection->statement('create table users (id bigint unsigned not null primary key)');

        $snapshot = (new MySqlSchemaInspector())->inspect(
            $this->connection,
            ['migrations', 'AUDIT_EVENTS'],
        );

        self::assertSame(['users'], array_column($snapshot->toArray()['tables'], 'name'));
    }

    public function test_it_refuses_views(): void
    {
        $this->connection->statement('create table users (id bigint unsigned not null primary key)');
        $this->connection->statement('create view active_users as select id from users');

        $this->expectUnsupported('view');
    }

    public function test_it_refuses_triggers(): void
    {
        $this->connection->statement('create table events (id bigint unsigned not null primary key, touched int not null default 0)');
        $this->connection->unprepared(
            'create trigger touch_event before update on events for each row set new.touched = 1',
        );

        $this->expectUnsupported('trigger');
    }

    public function test_it_refuses_check_constraints(): void
    {
        $this->connection->statement(
            'create table scores (id bigint unsigned not null primary key, score int not null, constraint scores_positive check (score >= 0))',
        );

        $this->expectUnsupported('check_constraint');
    }

    public function test_it_refuses_index_prefix_lengths(): void
    {
        $this->connection->statement(
            'create table users (id bigint unsigned not null primary key, email varchar(255) not null, index users_email_prefix (email(20)))',
        );

        $this->expectUnsupported('index_prefix_length');
    }

    public function test_it_refuses_column_on_update_expressions(): void
    {
        $this->connection->statement(
            'create table events (id bigint unsigned not null primary key, touched_at timestamp not null default current_timestamp on update current_timestamp)',
        );

        $this->expectUnsupported('column_on_update');
    }

    public function test_it_refuses_partitioned_tables(): void
    {
        $this->connection->statement(
            'create table events (id bigint unsigned not null primary key) partition by hash(id) partitions 2',
        );

        $this->expectUnsupported('partition');
    }

    private function expectUnsupported(string $feature): void
    {
        $this->expectException(UnsupportedSchemaFeature::class);
        $this->expectExceptionMessage("schema feature [{$feature}]");

        (new MySqlSchemaInspector())->inspect($this->connection);
    }

    private function resetDatabase(): void
    {
        $schema = $this->connection->getSchemaBuilder();

        $schema->dropAllViews();
        $schema->dropAllTables();
    }

    private function runMigration(GeneratedMigration $generated): void
    {
        $path = tempnam(sys_get_temp_dir(), 'migrafold-generated-');

        if ($path === false || file_put_contents($path, $generated->contents) === false) {
            throw new RuntimeException('Unable to create a generated migration fixture.');
        }

        try {
            $migration = require $path;
        } finally {
            unlink($path);
        }

        if (! $migration instanceof Migration || ! is_callable([$migration, 'up'])) {
            throw new RuntimeException('Generated migration fixture did not return a migration.');
        }

        $migration->up();
    }

    private function environment(string $name): string
    {
        $value = getenv($name);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Required environment variable [{$name}] is missing.");
        }

        return $value;
    }
}
