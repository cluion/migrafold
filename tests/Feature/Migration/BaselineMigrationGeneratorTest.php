<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use Cluion\Migrafold\Migration\BaselineMigrationGenerator;
use Cluion\Migrafold\Migration\Exception\UnsupportedMigrationGeneration;
use Cluion\Migrafold\Migration\TableMigrationRenderer;
use Cluion\Migrafold\Schema\Definition\ColumnDefinition;
use Cluion\Migrafold\Schema\Definition\ForeignKeyDefinition;
use Cluion\Migrafold\Schema\Definition\TableDefinition;
use Cluion\Migrafold\Schema\SqliteSchemaInspector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class BaselineMigrationGeneratorTest extends TestCase
{
    public function test_it_generates_one_ordered_migration_per_table_and_replays_the_same_schema(): void
    {
        $this->createSourceSchema();

        $inspector = new SqliteSchemaInspector();
        $source = $inspector->inspect($this->connection());
        $generated = (new BaselineMigrationGenerator())->generate($source, '2026_09_11');

        self::assertSame([
            '2026_09_11_000001_create_roles_baseline.php',
            '2026_09_11_000002_create_users_baseline.php',
        ], array_column($generated, 'filename'));
        self::assertSame(['roles', 'users'], array_column($generated, 'table'));
        self::assertStringContainsString("if (Schema::hasTable('users'))", $generated[1]->contents);
        self::assertStringContainsString('MGF-ROLLBACK-001:', $generated[1]->contents);
        self::assertStringContainsString("\$table->foreign(['role_id'])", $generated[1]->contents);
        self::assertSame($this->fixture('create_roles_baseline.php.fixture'), $generated[0]->contents);
        self::assertSame($this->fixture('create_users_baseline.php.fixture'), $generated[1]->contents);

        $this->resetToEmptyDatabase();
        $directory = $this->migrationDirectory($this->migrationMap($generated));

        $this->migrator()->run($directory);

        self::assertSame($source->toJson(), $inspector->inspect($this->connection())->toJson());
        self::assertSame(2, DB::table('migrations')->count());
    }

    public function test_existing_table_guards_skip_creation_and_still_log_the_baselines(): void
    {
        $this->createSourceSchema();

        $inspector = new SqliteSchemaInspector();
        $source = $inspector->inspect($this->connection());
        $generated = (new BaselineMigrationGenerator())->generate($source, '2026_09_11');
        $directory = $this->migrationDirectory($this->migrationMap($generated));

        $this->migrator()->run($directory);

        self::assertSame($source->toJson(), $inspector->inspect($this->connection())->toJson());
        self::assertSame(2, DB::table('migrations')->count());
    }

    public function test_generated_rollbacks_fail_without_dropping_tables_or_records(): void
    {
        $this->createSourceSchema();

        $generated = (new BaselineMigrationGenerator())->generate(
            (new SqliteSchemaInspector())->inspect($this->connection()),
            '2026_09_11',
        );
        $this->resetToEmptyDatabase();
        $directory = $this->migrationDirectory($this->migrationMap($generated));
        $this->migrator()->run($directory);

        try {
            $this->migrator()->rollback($directory);
            self::fail('Generated baselines unexpectedly rolled back.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('MGF-ROLLBACK-001:', $exception->getMessage());
        }

        self::assertTrue(Schema::hasTable('roles'));
        self::assertTrue(Schema::hasTable('users'));
        self::assertSame(2, DB::table('migrations')->count());
    }

    public function test_dependencies_override_alphabetical_table_order(): void
    {
        $parent = $this->table('z_parents');
        $child = $this->table('a_children', [new ForeignKeyDefinition(
            name: null,
            columns: ['parent_id'],
            foreignSchema: 'main',
            foreignTable: 'z_parents',
            foreignColumns: ['id'],
            onUpdate: 'no action',
            onDelete: 'cascade',
        )]);
        $source = (new SqliteSchemaInspector())->inspect($this->connection());
        $snapshot = new \Cluion\Migrafold\Schema\Definition\SchemaSnapshot(
            formatVersion: $source->formatVersion,
            driver: $source->driver,
            capabilities: $source->capabilities,
            tables: [$child, $parent],
        );

        $generated = (new BaselineMigrationGenerator())->generate($snapshot, '2026_09_11');

        self::assertSame(['z_parents', 'a_children'], array_column($generated, 'table'));
    }

    public function test_it_refuses_cyclic_table_dependencies(): void
    {
        $left = $this->table('left_nodes', [$this->foreignKey('right_nodes')]);
        $right = $this->table('right_nodes', [$this->foreignKey('left_nodes')]);
        $source = (new SqliteSchemaInspector())->inspect($this->connection());
        $snapshot = new \Cluion\Migrafold\Schema\Definition\SchemaSnapshot(
            formatVersion: $source->formatVersion,
            driver: $source->driver,
            capabilities: $source->capabilities,
            tables: [$left, $right],
        );

        $this->expectException(UnsupportedMigrationGeneration::class);
        $this->expectExceptionMessage('MGF-GENERATE-001: Cannot generate a safe baseline: cyclic table dependencies');

        (new BaselineMigrationGenerator())->generate($snapshot, '2026_09_11');
    }

    public function test_it_refuses_foreign_keys_to_tables_outside_the_snapshot(): void
    {
        $child = $this->table('children', [$this->foreignKey('missing_parents')]);
        $source = (new SqliteSchemaInspector())->inspect($this->connection());
        $snapshot = new \Cluion\Migrafold\Schema\Definition\SchemaSnapshot(
            formatVersion: $source->formatVersion,
            driver: $source->driver,
            capabilities: $source->capabilities,
            tables: [$child],
        );

        $this->expectException(UnsupportedMigrationGeneration::class);
        $this->expectExceptionMessage('references missing table [main.missing_parents]');

        (new BaselineMigrationGenerator())->generate($snapshot, '2026_09_11');
    }

    public function test_it_refuses_column_types_without_a_lossless_blueprint_mapping(): void
    {
        $table = new TableDefinition(
            name: 'locations',
            schema: 'main',
            collation: null,
            engine: null,
            comment: null,
            columns: [new ColumnDefinition(
                name: 'point',
                type: 'geometry',
                typeName: 'geometry',
                nullable: false,
                default: null,
                autoIncrement: false,
                collation: null,
                comment: null,
                generation: null,
            )],
            indexes: [],
            foreignKeys: [],
        );

        $this->expectException(UnsupportedMigrationGeneration::class);
        $this->expectExceptionMessage('column [point] has unsupported type [geometry]');

        (new TableMigrationRenderer())->render($table);
    }

    public function test_common_numeric_and_binary_columns_replay_the_same_sqlite_schema(): void
    {
        Schema::create('measurements', static function (Blueprint $table): void {
            $table->id();
            $table->float('ratio', 24)->default(1.25);
            $table->double('score')->default(2.5);
            $table->decimal('amount', 10, 2)->default(0);
            $table->binary('payload');
        });

        $inspector = new SqliteSchemaInspector();
        $source = $inspector->inspect($this->connection());
        $generated = (new BaselineMigrationGenerator())->generate($source, '2026_09_12');

        self::assertStringContainsString("\$table->float('ratio', 24)->default('1.25');", $generated[0]->contents);
        self::assertStringContainsString("\$table->double('score')->default('2.5');", $generated[0]->contents);
        self::assertStringContainsString("\$table->decimal('amount')->default('0');", $generated[0]->contents);
        self::assertStringContainsString("\$table->binary('payload');", $generated[0]->contents);

        $this->resetToEmptyDatabase();
        $directory = $this->migrationDirectory($this->migrationMap($generated));
        $this->migrator()->run($directory);

        self::assertSame($source->toJson(), $inspector->inspect($this->connection())->toJson());
    }

    private function createSourceSchema(): void
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
    }

    private function resetToEmptyDatabase(): void
    {
        $this->connection()->getSchemaBuilder()->dropAllTables();
        $this->migrator()->getRepository()->createRepository();
    }

    /**
     * @param list<\Cluion\Migrafold\Migration\GeneratedMigration> $generated
     * @return array<string, string>
     */
    private function migrationMap(array $generated): array
    {
        $migrations = [];

        foreach ($generated as $migration) {
            $migrations[pathinfo($migration->filename, PATHINFO_FILENAME)] = $migration->contents;
        }

        return $migrations;
    }

    /** @param list<ForeignKeyDefinition> $foreignKeys */
    private function table(string $name, array $foreignKeys = []): TableDefinition
    {
        $columns = [new ColumnDefinition(
            name: 'id',
            type: 'integer',
            typeName: 'integer',
            nullable: false,
            default: null,
            autoIncrement: false,
            collation: null,
            comment: null,
            generation: null,
        )];

        foreach ($foreignKeys as $foreignKey) {
            foreach ($foreignKey->columns as $column) {
                $columns[] = new ColumnDefinition(
                    name: $column,
                    type: 'integer',
                    typeName: 'integer',
                    nullable: false,
                    default: null,
                    autoIncrement: false,
                    collation: null,
                    comment: null,
                    generation: null,
                );
            }
        }

        return new TableDefinition(
            name: $name,
            schema: 'main',
            collation: null,
            engine: null,
            comment: null,
            columns: $columns,
            indexes: [],
            foreignKeys: $foreignKeys,
        );
    }

    private function foreignKey(string $table): ForeignKeyDefinition
    {
        return new ForeignKeyDefinition(
            name: null,
            columns: ['other_id'],
            foreignSchema: 'main',
            foreignTable: $table,
            foreignColumns: ['id'],
            onUpdate: 'no action',
            onDelete: 'cascade',
        );
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/Fixtures/Migration/'.$name);

        if ($contents === false) {
            throw new RuntimeException("Unable to read migration fixture [{$name}].");
        }

        return $contents;
    }
}
