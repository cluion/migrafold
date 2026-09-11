<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use Cluion\Migrafold\Schema\Definition\TableDefinition;
use Cluion\Migrafold\Schema\Exception\UnsupportedDatabaseDriver;
use Cluion\Migrafold\Schema\Exception\UnsupportedSchemaFeature;
use Cluion\Migrafold\Schema\SqliteSchemaInspector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class SqliteSchemaInspectorTest extends TestCase
{
    public function test_it_builds_a_canonical_snapshot_of_supported_sqlite_schema(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('email')->collation('nocase');
            $table->string('nickname')->nullable()->default('guest');
            $table->string('normalized_email')->virtualAs('lower(email)');
            $table->index(['nickname', 'email'], 'users_lookup_index');
        });

        $snapshot = (new SqliteSchemaInspector())->inspect($this->connection());

        self::assertSame('1', $snapshot->formatVersion);
        self::assertSame('sqlite', $snapshot->driver);
        self::assertSame(['roles', 'users'], array_map(
            static fn (TableDefinition $table): string => $table->name,
            $snapshot->tables,
        ));
        self::assertContains('generated_columns', $snapshot->capabilities->supported);
        self::assertContains('named_foreign_keys', $snapshot->capabilities->unsupported);

        $users = $this->table($snapshot->tables, 'users');

        self::assertSame(
            ['id', 'role_id', 'email', 'nickname', 'normalized_email'],
            array_column($users->toArray()['columns'], 'name'),
        );
        self::assertSame('nocase', $users->columns[2]->collation);
        self::assertSame("'guest'", $users->columns[3]->default);
        $generation = $users->columns[4]->generation;
        self::assertNotNull($generation);
        self::assertSame('virtual', $generation->type);
        self::assertSame('lower(email)', $generation->expression);
        self::assertSame(
            ['primary', 'users_lookup_index'],
            array_map(static fn ($index): string => $index->name, $users->indexes),
        );
        self::assertCount(1, $users->foreignKeys);
        self::assertSame(['role_id'], $users->foreignKeys[0]->columns);
        self::assertSame('roles', $users->foreignKeys[0]->foreignTable);
        self::assertSame('cascade', $users->foreignKeys[0]->onUpdate);
        self::assertSame('restrict', $users->foreignKeys[0]->onDelete);
        self::assertSame($this->fixture('sqlite-supported.json'), $snapshot->toJson());
        self::assertStringEndsWith("\n", $snapshot->toJson());
    }

    public function test_serialization_and_fingerprint_are_deterministic_and_schema_sensitive(): void
    {
        Schema::create('zebra', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('alpha', function (Blueprint $table): void {
            $table->id();
        });

        $inspector = new SqliteSchemaInspector();
        $first = $inspector->inspect($this->connection());
        $second = $inspector->inspect($this->connection());

        self::assertSame($first->toJson(), $second->toJson());
        self::assertSame($first->fingerprint(), $second->fingerprint());

        Schema::table('alpha', function (Blueprint $table): void {
            $table->string('name')->index();
        });

        $changed = $inspector->inspect($this->connection());

        self::assertNotSame($first->fingerprint(), $changed->fingerprint());
        self::assertSame(['alpha', 'zebra'], array_map(
            static fn (TableDefinition $table): string => $table->name,
            $changed->tables,
        ));
    }

    public function test_explicit_exclusions_are_case_insensitive(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });

        $snapshot = (new SqliteSchemaInspector())->inspect(
            $this->connection(),
            ['migrations', 'AUDIT_EVENTS'],
        );

        self::assertSame(['users'], array_map(
            static fn (TableDefinition $table): string => $table->name,
            $snapshot->tables,
        ));
    }

    public function test_it_refuses_views(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        $this->connection()->statement('create view active_users as select id from users');

        $this->expectException(UnsupportedSchemaFeature::class);
        $this->expectExceptionMessage('MGF-SCHEMA-002: Unsupported SQLite schema feature [view]');

        (new SqliteSchemaInspector())->inspect($this->connection());
    }

    public function test_it_refuses_triggers(): void
    {
        $this->connection()->statement('create table events (id integer primary key, touched integer not null default 0)');
        $this->connection()->statement(
            'create trigger touch_event after update on events begin update events set touched = 1 where id = new.id; end',
        );

        $this->expectException(UnsupportedSchemaFeature::class);
        $this->expectExceptionMessage('MGF-SCHEMA-002: Unsupported SQLite schema feature [trigger] detected at [touch_event].');

        (new SqliteSchemaInspector())->inspect($this->connection());
    }

    public function test_it_refuses_check_constraints(): void
    {
        $this->connection()->statement(
            'create table scores (id integer primary key, score integer not null check (score >= 0))',
        );

        $this->expectException(UnsupportedSchemaFeature::class);
        $this->expectExceptionMessage('MGF-SCHEMA-002: Unsupported SQLite schema feature [check_constraint]');

        (new SqliteSchemaInspector())->inspect($this->connection());
    }

    public function test_it_refuses_partial_indexes(): void
    {
        $this->connection()->statement('create table users (id integer primary key, email text, deleted_at text)');
        $this->connection()->statement(
            'create index active_users_email on users (email) where deleted_at is null',
        );

        $this->expectException(UnsupportedSchemaFeature::class);
        $this->expectExceptionMessage('MGF-SCHEMA-002: Unsupported SQLite schema feature [partial_index]');

        (new SqliteSchemaInspector())->inspect($this->connection());
    }

    public function test_it_refuses_expression_indexes(): void
    {
        $this->connection()->statement('create table users (id integer primary key, email text not null)');
        $this->connection()->statement('create index users_lower_email on users (lower(email))');

        $this->expectException(UnsupportedSchemaFeature::class);
        $this->expectExceptionMessage('MGF-SCHEMA-002: Unsupported SQLite schema feature [expression_index]');

        (new SqliteSchemaInspector())->inspect($this->connection());
    }

    public function test_it_refuses_a_connection_declared_as_another_driver(): void
    {
        $connection = new SQLiteConnection(
            new PDO('sqlite::memory:'),
            ':memory:',
            '',
            ['driver' => 'mysql'],
        );

        $this->expectException(UnsupportedDatabaseDriver::class);
        $this->expectExceptionMessage(
            'MGF-SCHEMA-001: Database driver [mysql] is not supported by the SQLite inspector.',
        );

        (new SqliteSchemaInspector())->inspect($connection);
    }

    /**
     * @param list<TableDefinition> $tables
     */
    private function table(array $tables, string $name): TableDefinition
    {
        foreach ($tables as $table) {
            if ($table->name === $name) {
                return $table;
            }
        }

        self::fail("Table [{$name}] was not found in the snapshot.");
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/Fixtures/Schema/'.$name);

        if ($contents === false) {
            throw new RuntimeException("Unable to read schema fixture [{$name}].");
        }

        return $contents;
    }
}
