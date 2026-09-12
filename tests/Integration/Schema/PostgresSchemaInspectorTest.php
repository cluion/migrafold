<?php

declare(strict_types=1);

namespace Tests\Integration\Schema;

use Cluion\Migrafold\Activation\DatabaseMigrationRecordTransaction;
use Cluion\Migrafold\Activation\Exception\MigrationRecordActivationFailed;
use Cluion\Migrafold\Migration\BaselineMigrationGenerator;
use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Schema\Exception\UnsupportedSchemaFeature;
use Cluion\Migrafold\Schema\PostgresSchemaInspector;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PostgresSchemaInspectorTest extends TestCase
{
    private PostgresConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('MIGRAFOLD_DATABASE_TESTS') !== '1') {
            self::markTestSkipped('Real database tests require MIGRAFOLD_DATABASE_TESTS=1.');
        }

        if (getenv('MIGRAFOLD_DB_DRIVER') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL inspector tests require MIGRAFOLD_DB_DRIVER=pgsql.');
        }

        $database = $this->environment('MIGRAFOLD_DB_DATABASE');

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException('Real database tests may only use a *_testing database.');
        }

        $host = $this->environment('MIGRAFOLD_DB_HOST');
        $port = $this->environment('MIGRAFOLD_DB_PORT');
        $username = $this->environment('MIGRAFOLD_DB_USERNAME');
        $password = $this->environment('MIGRAFOLD_DB_PASSWORD');
        $pdo = new PDO(
            "pgsql:host={$host};port={$port};dbname={$database}",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $this->connection = new PostgresConnection($pdo, $database, '', [
            'driver' => 'pgsql',
            'database' => $database,
            'schema' => 'public',
            'search_path' => 'public',
            'prefix' => '',
        ]);

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

    public function test_it_builds_a_deterministic_canonical_snapshot_on_a_real_server(): void
    {
        $this->createSupportedSchema();

        $inspector = new PostgresSchemaInspector();
        $snapshot = $inspector->inspect($this->connection);
        $again = $inspector->inspect($this->connection);

        self::assertSame('pgsql', $snapshot->driver);
        self::assertSame(['roles', 'users'], array_column($snapshot->toArray()['tables'], 'name'));
        self::assertSame($snapshot->toJson(), $again->toJson());
        self::assertSame($snapshot->fingerprint(), $again->fingerprint());
        self::assertSame(
            '16bae5f234f2d7c02f16fb4b5e1d5ed609a1b3256123d2a2e173a924321bd7a4',
            $snapshot->fingerprint(),
        );
        self::assertContains('named_foreign_keys', $snapshot->capabilities->supported);
        self::assertContains('partial_indexes', $snapshot->capabilities->unsupported);
        self::assertContains('identity_columns', $snapshot->capabilities->unsupported);

        $users = $snapshot->tables[1];

        self::assertNull($users->schema);
        self::assertNull($users->engine);
        self::assertSame('Application users', $users->comment);
        self::assertSame(
            ['id', 'role_id', 'email', 'nickname', 'enabled', 'score', 'metadata', 'public_id', 'normalized_email', 'created_at'],
            array_column($users->toArray()['columns'], 'name'),
        );
        self::assertTrue($users->columns[0]->autoIncrement);
        self::assertNull($users->columns[0]->default);
        self::assertSame('Login address', $users->columns[2]->comment);
        self::assertSame("'guest'", $users->columns[3]->default);
        self::assertTrue($users->columns[4]->default);
        self::assertSame('decimal(10,2)', $users->columns[5]->type);
        self::assertSame('1.25', $users->columns[5]->default);
        self::assertSame('jsonb', $users->columns[6]->type);
        self::assertSame('uuid', $users->columns[7]->type);
        self::assertSame('stored', $users->columns[8]->generation?->type);
        self::assertSame('timestamp(0)', $users->columns[9]->type);
        self::assertSame('CURRENT_TIMESTAMP', $users->columns[9]->default);
        self::assertSame(
            ['users_pkey', 'users_email_unique', 'users_lookup_index'],
            array_column($users->toArray()['indexes'], 'name'),
        );
        self::assertSame('users_role_id_foreign', $users->foreignKeys[0]->name);
        self::assertNull($users->foreignKeys[0]->foreignSchema);
        self::assertSame('cascade', $users->foreignKeys[0]->onUpdate);
        self::assertSame('restrict', $users->foreignKeys[0]->onDelete);
    }

    public function test_exclusions_are_case_insensitive(): void
    {
        $this->connection->statement('create table audit_events (id bigint primary key)');
        $this->connection->statement('create table users (id bigint primary key)');

        $snapshot = (new PostgresSchemaInspector())->inspect(
            $this->connection,
            ['migrations', 'AUDIT_EVENTS'],
        );

        self::assertSame(['users'], array_column($snapshot->toArray()['tables'], 'name'));
    }

    public function test_generated_baselines_replay_the_same_schema_on_a_real_server(): void
    {
        $this->createSupportedSchema();

        $inspector = new PostgresSchemaInspector();
        $source = $inspector->inspect($this->connection);
        $generated = (new BaselineMigrationGenerator())->generate($source, '2026_09_12');

        self::assertStringContainsString("\$table->bigIncrements('id');", $generated[0]->contents);
        self::assertStringContainsString("\$table->boolean('enabled')->default(true);", $generated[1]->contents);
        self::assertStringContainsString("\$table->jsonb('metadata')->nullable();", $generated[1]->contents);
        self::assertStringContainsString("->storedAs('lower((email)::text)')", $generated[1]->contents);

        $this->resetDatabase();

        foreach ($generated as $migration) {
            $this->runMigration($migration);
        }

        self::assertSame($source->toJson(), $inspector->inspect($this->connection)->toJson());
    }

    public function test_postgres_specific_physical_types_round_trip_on_a_real_server(): void
    {
        $this->connection->statement(<<<'SQL'
create table measurements (
    id bigserial primary key,
    ratio real not null default 1.25,
    score double precision not null default 2.5,
    payload bytea not null,
    happened_at timestamp(3) with time zone null,
    local_time time(4) with time zone null
)
SQL);

        $inspector = new PostgresSchemaInspector();
        $source = $inspector->inspect($this->connection);
        $generated = (new BaselineMigrationGenerator())->generate($source, '2026_09_12');

        self::assertStringContainsString("\$table->addColumn('real', 'ratio')->default(1.25);", $generated[0]->contents);
        self::assertStringContainsString("\$table->double('score')->default(2.5);", $generated[0]->contents);
        self::assertStringContainsString("\$table->binary('payload');", $generated[0]->contents);
        self::assertStringContainsString("\$table->timestampTz('happened_at', 3)->nullable();", $generated[0]->contents);
        self::assertStringContainsString("\$table->timeTz('local_time', 4)->nullable();", $generated[0]->contents);

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

    public function test_migration_record_transaction_refuses_a_contended_lock_without_waiting(): void
    {
        $contender = $this->newConnection();
        $transaction = new DatabaseMigrationRecordTransaction(0);

        try {
            $transaction->run(
                $this->connection,
                'integration:migrations',
                function () use ($contender, $transaction): void {
                    try {
                        $transaction->run($contender, 'integration:migrations', static fn (): null => null);
                        self::fail('Contended PostgreSQL advisory lock was unexpectedly acquired.');
                    } catch (MigrationRecordActivationFailed $exception) {
                        self::assertStringContainsString(
                            'could not acquire the PostgreSQL migration-record advisory lock',
                            $exception->getMessage(),
                        );
                    }

                    self::assertSame(0, $contender->transactionLevel());
                },
            );
        } finally {
            $contender->disconnect();
        }

        self::assertSame(0, $this->connection->transactionLevel());
    }

    public function test_it_refuses_non_public_search_paths(): void
    {
        $connection = new PostgresConnection(
            $this->connection->getPdo(),
            $this->connection->getDatabaseName(),
            '',
            [
                'driver' => 'pgsql',
                'schema' => 'public',
                'search_path' => 'public,tenant',
                'prefix' => '',
            ],
        );

        $this->expectUnsupported('search_path');

        (new PostgresSchemaInspector())->inspect($connection);
    }

    #[DataProvider('unsupportedSchemaProvider')]
    public function test_it_refuses_unsupported_schema_features(string $feature, string $sql): void
    {
        if ($this->connection->getPdo()->exec($sql) === false) {
            self::fail("Failed to prepare unsupported PostgreSQL feature [{$feature}].");
        }

        $this->expectUnsupported($feature);

        (new PostgresSchemaInspector())->inspect($this->connection);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function unsupportedSchemaProvider(): iterable
    {
        yield 'view' => [
            'view',
            'create table users (id bigint); create view active_users as select id from users',
        ];
        yield 'materialized view' => [
            'materialized_view',
            'create table users (id bigint); create materialized view active_users as select id from users',
        ];
        yield 'trigger' => [
            'trigger',
            <<<'SQL'
create table events (id bigint);
create function touch_event() returns trigger language plpgsql as $$ begin return new; end $$;
create trigger touch_event before update on events for each row execute function touch_event()
SQL,
        ];
        yield 'check constraint' => [
            'check_constraint',
            'create table scores (id bigint, score integer constraint scores_positive check (score >= 0))',
        ];
        yield 'partition' => [
            'partition',
            'create table events (id bigint not null) partition by range (id)',
        ];
        yield 'unlogged table' => [
            'unlogged_table',
            'create unlogged table events (id bigint)',
        ];
        yield 'row-level security' => [
            'row_level_security',
            'create table secrets (id bigint); alter table secrets enable row level security',
        ];
        yield 'identity column' => [
            'identity_column',
            'create table events (id bigint generated always as identity primary key)',
        ];
        yield 'array column' => [
            'array_column',
            'create table events (id bigint, tags text[])',
        ];
        yield 'custom type' => [
            'custom_type',
            'create type event_state as enum (\'draft\', \'published\'); create table events (state event_state)',
        ];
        yield 'deferrable constraint' => [
            'deferrable_constraint',
            'create table users (id bigint primary key deferrable initially deferred)',
        ];
        yield 'unvalidated constraint' => [
            'unvalidated_constraint',
            'create table roles (id bigint primary key); create table users (role_id bigint); alter table users add constraint users_role_id_foreign foreign key (role_id) references roles(id) not valid',
        ];
        yield 'standalone sequence' => [
            'standalone_sequence',
            'create sequence event_numbers; create table events (id bigint)',
        ];
        yield 'partial index' => [
            'partial_index',
            'create table users (email varchar(255), deleted_at timestamp); create index active_users_email on users (email) where deleted_at is null',
        ];
        yield 'expression index' => [
            'expression_index',
            'create table users (email varchar(255)); create index users_lower_email on users (lower(email))',
        ];
        yield 'included index columns' => [
            'included_index_columns',
            'create table users (email varchar(255), name varchar(255)); create index users_email_index on users (email) include (name)',
        ];
        yield 'nulls not distinct index' => [
            'index_nulls_not_distinct',
            'create table users (email varchar(255)); create unique index users_email_unique on users (email) nulls not distinct',
        ];
        yield 'descending index' => [
            'index_ordering',
            'create table events (occurred_at timestamp); create index events_occurred_at_index on events (occurred_at desc)',
        ];
        yield 'custom operator class' => [
            'index_operator_class',
            'create table users (email text); create index users_email_pattern on users (email text_pattern_ops)',
        ];
        yield 'non-btree index' => [
            'non_btree_index',
            'create table events (external_id bigint); create index events_external_id_hash on events using hash (external_id)',
        ];
        yield 'multi-schema table' => [
            'multi_schema_object',
            'create schema tenant; create table tenant.users (id bigint)',
        ];
        yield 'unsupported default expression' => [
            'column_default_expression',
            'create table users (name text default lower(\'GUEST\'))',
        ];
        yield 'unsupported column type' => [
            'column_type',
            'create table servers (address inet)',
        ];
    }

    private function createSupportedSchema(): void
    {
        $this->connection->unprepared(<<<'SQL'
create table roles (
    id bigserial primary key,
    code varchar(32) not null constraint roles_code_unique unique
);
comment on table roles is 'Role catalog';

create table users (
    id bigserial primary key,
    role_id bigint not null,
    email varchar(255) not null,
    nickname varchar(80) null default 'guest',
    enabled boolean not null default true,
    score numeric(10, 2) not null default 1.25,
    metadata jsonb null,
    public_id uuid not null,
    normalized_email varchar(255) generated always as (lower(email)) stored,
    created_at timestamp(0) without time zone null default current_timestamp,
    constraint users_email_unique unique (email),
    constraint users_role_id_foreign foreign key (role_id) references roles (id)
        on update cascade on delete restrict
);
comment on table users is 'Application users';
comment on column users.email is 'Login address';
create index users_lookup_index on users (nickname, email)
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

    private function newConnection(): PostgresConnection
    {
        $database = $this->environment('MIGRAFOLD_DB_DATABASE');
        $pdo = new PDO(
            'pgsql:host='.$this->environment('MIGRAFOLD_DB_HOST')
                .';port='.$this->environment('MIGRAFOLD_DB_PORT')
                .';dbname='.$database,
            $this->environment('MIGRAFOLD_DB_USERNAME'),
            $this->environment('MIGRAFOLD_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        return new PostgresConnection($pdo, $database, '', [
            'driver' => 'pgsql',
            'database' => $database,
            'schema' => 'public',
            'search_path' => 'public',
            'prefix' => '',
        ]);
    }

    private function expectUnsupported(string $feature): void
    {
        $this->expectException(UnsupportedSchemaFeature::class);
        $this->expectExceptionMessage("schema feature [{$feature}]");
    }

    private function resetDatabase(): void
    {
        $this->connection->unprepared(<<<'SQL'
drop schema if exists tenant cascade;
drop schema if exists public cascade;
create schema public
SQL);
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
