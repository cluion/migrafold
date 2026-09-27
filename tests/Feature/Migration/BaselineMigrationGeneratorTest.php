<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use Cluion\Migrafold\Migration\BaselineMigrationGenerator;
use Cluion\Migrafold\Migration\Exception\UnsupportedMigrationGeneration;
use Cluion\Migrafold\Migration\TableMigrationRenderer;
use Cluion\Migrafold\Schema\Definition\CheckConstraintDefinition;
use Cluion\Migrafold\Schema\Definition\ColumnDefinition;
use Cluion\Migrafold\Schema\Definition\ExpressionIndexDefinition;
use Cluion\Migrafold\Schema\Definition\ForeignKeyDefinition;
use Cluion\Migrafold\Schema\Definition\GinIndexDefinition;
use Cluion\Migrafold\Schema\Definition\GeneratedColumnDefinition;
use Cluion\Migrafold\Schema\Definition\MultiKeyExpressionIndexDefinition;
use Cluion\Migrafold\Schema\Definition\PartialIndexDefinition;
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

    public function test_postgres_checks_are_quoted_and_rendered_after_table_creation(): void
    {
        $table = new TableDefinition(
            name: 'Scores',
            schema: null,
            collation: null,
            engine: null,
            comment: null,
            columns: [new ColumnDefinition(
                name: 'id',
                type: 'integer',
                typeName: 'integer',
                nullable: false,
                default: null,
                autoIncrement: false,
                collation: null,
                comment: null,
                generation: null,
            )],
            indexes: [],
            foreignKeys: [],
            checkConstraints: [new CheckConstraintDefinition('score"range', 'id >= 0')],
        );

        $contents = (new TableMigrationRenderer())->render($table, 'pgsql');

        self::assertStringContainsString('use Illuminate\\Support\\Facades\\DB;', $contents);
        self::assertStringContainsString('ALTER TABLE "public"."Scores" ADD CONSTRAINT "score""range" CHECK (id >= 0)', $contents);
        self::assertLessThan(
            strpos($contents, 'ADD CONSTRAINT'),
            strpos($contents, 'Schema::create'),
        );

        $this->expectException(UnsupportedMigrationGeneration::class);
        $this->expectExceptionMessage('require the public PostgreSQL schema');

        (new TableMigrationRenderer())->render($table, 'sqlite');
    }

    public function test_postgres_expression_indexes_are_quoted_and_rendered_after_table_creation(): void
    {
        $table = new TableDefinition(
            name: 'Odd"Users',
            schema: null,
            collation: null,
            engine: null,
            comment: null,
            columns: [new ColumnDefinition(
                name: 'email',
                type: 'text',
                typeName: 'text',
                nullable: true,
                default: null,
                autoIncrement: false,
                collation: null,
                comment: null,
                generation: null,
            )],
            indexes: [],
            foreignKeys: [],
            expressionIndexes: [new ExpressionIndexDefinition('email"ci', 'lower(email)', true)],
        );

        $contents = (new TableMigrationRenderer())->render($table, 'pgsql');

        self::assertStringContainsString('CREATE UNIQUE INDEX "email""ci" ON "public"."Odd""Users" USING btree (lower(email))', $contents);
        self::assertLessThan(strpos($contents, 'CREATE UNIQUE INDEX'), strpos($contents, 'Schema::create'));

        $this->expectException(UnsupportedMigrationGeneration::class);
        $this->expectExceptionMessage('require the public PostgreSQL schema');

        (new TableMigrationRenderer())->render($table, 'sqlite');
    }

    public function test_postgres_multi_key_expression_indexes_render_after_table_creation(): void
    {
        $source = $this->table('Odd"Releases');
        $table = new TableDefinition(
            name: $source->name,
            schema: null,
            collation: null,
            engine: null,
            comment: null,
            columns: $source->columns,
            indexes: [],
            foreignKeys: [],
            multiKeyExpressionIndexes: [new MultiKeyExpressionIndexDefinition('version"ci', ['id', 'lower(version)'], true)],
        );

        $contents = (new TableMigrationRenderer())->render($table, 'pgsql');

        self::assertStringContainsString('CREATE UNIQUE INDEX "version""ci" ON "public"."Odd""Releases" USING btree (id, lower(version))', $contents);
        self::assertLessThan(strpos($contents, 'CREATE UNIQUE INDEX'), strpos($contents, 'Schema::create'));

        $this->expectException(UnsupportedMigrationGeneration::class);
        $this->expectExceptionMessage('require the public PostgreSQL schema');

        (new TableMigrationRenderer())->render($table, 'sqlite');
    }

    public function test_postgres_multi_key_expression_indexes_refuse_a_single_key(): void
    {
        $source = $this->table('releases');
        $table = new TableDefinition(
            name: $source->name,
            schema: null,
            collation: null,
            engine: null,
            comment: null,
            columns: $source->columns,
            indexes: [],
            foreignKeys: [],
            multiKeyExpressionIndexes: [new MultiKeyExpressionIndexDefinition('releases_ci', ['lower(version)'], false)],
        );

        $this->expectException(UnsupportedMigrationGeneration::class);
        $this->expectExceptionMessage('requires at least two keys');

        (new TableMigrationRenderer())->render($table, 'pgsql');
    }

    public function test_postgres_gin_indexes_render_after_table_creation(): void
    {
        $source = $this->table('Odd"Docs');
        $table = new TableDefinition(
            name: $source->name,
            schema: null,
            collation: null,
            engine: null,
            comment: null,
            columns: $source->columns,
            indexes: [],
            foreignKeys: [],
            ginIndexes: [new GinIndexDefinition('payload"gin', '"Odd""Payload"')],
        );

        $contents = (new TableMigrationRenderer())->render($table, 'pgsql');

        self::assertStringContainsString('CREATE INDEX "payload""gin" ON "public"."Odd""Docs" USING gin ("Odd""Payload")', $contents);
        self::assertLessThan(strpos($contents, 'CREATE INDEX'), strpos($contents, 'Schema::create'));

        $this->expectException(UnsupportedMigrationGeneration::class);
        $this->expectExceptionMessage('require the public PostgreSQL schema');

        (new TableMigrationRenderer())->render($table, 'sqlite');
    }

    public function test_postgres_generated_tsvector_columns_use_the_native_blueprint_type(): void
    {
        $column = new ColumnDefinition(
            name: 'search_vector',
            type: 'tsvector',
            typeName: 'tsvector',
            nullable: true,
            default: null,
            autoIncrement: false,
            collation: null,
            comment: null,
            generation: new GeneratedColumnDefinition('stored', "to_tsvector('simple', title)"),
        );

        $contents = (new \Cluion\Migrafold\Migration\ColumnBlueprintRenderer())->render($column, 'pgsql');

        self::assertSame("\$table->tsvector('search_vector')->nullable()->storedAs('to_tsvector(\\'simple\\', title)');", $contents);
    }

    public function test_postgres_plain_tsvector_columns_are_refused_by_the_renderer(): void
    {
        $column = new ColumnDefinition(
            name: 'search_vector',
            type: 'tsvector',
            typeName: 'tsvector',
            nullable: true,
            default: null,
            autoIncrement: false,
            collation: null,
            comment: null,
            generation: null,
        );

        $this->expectException(UnsupportedMigrationGeneration::class);
        $this->expectExceptionMessage('must be a stored generated column');

        (new \Cluion\Migrafold\Migration\ColumnBlueprintRenderer())->render($column, 'pgsql');
    }

    public function test_postgres_partial_indexes_are_quoted_and_rendered_after_table_creation(): void
    {
        $table = new TableDefinition(
            name: 'Odd"Reports',
            schema: null,
            collation: null,
            engine: null,
            comment: null,
            columns: [new ColumnDefinition(
                name: 'status',
                type: 'text',
                typeName: 'text',
                nullable: true,
                default: null,
                autoIncrement: false,
                collation: null,
                comment: null,
                generation: null,
            )],
            indexes: [],
            foreignKeys: [],
            partialIndexes: [new PartialIndexDefinition('open"reports', ['status'], 'status IS NOT NULL', true)],
        );

        $contents = (new TableMigrationRenderer())->render($table, 'pgsql');

        self::assertStringContainsString('CREATE UNIQUE INDEX "open""reports" ON "public"."Odd""Reports" USING btree (status) WHERE status IS NOT NULL', $contents);
        self::assertLessThan(strpos($contents, 'CREATE UNIQUE INDEX'), strpos($contents, 'Schema::create'));

        $this->expectException(UnsupportedMigrationGeneration::class);
        $this->expectExceptionMessage('require the public PostgreSQL schema');

        (new TableMigrationRenderer())->render($table, 'sqlite');
    }

    public function test_postgres_partial_indexes_refuse_an_empty_key_list(): void
    {
        $source = $this->table('reports');
        $table = new TableDefinition(
            name: $source->name,
            schema: null,
            collation: null,
            engine: null,
            comment: null,
            columns: $source->columns,
            indexes: [],
            foreignKeys: [],
            partialIndexes: [new PartialIndexDefinition('reports_active', [], 'id IS NOT NULL', false)],
        );

        $this->expectException(UnsupportedMigrationGeneration::class);
        $this->expectExceptionMessage('has invalid keys or predicate');

        (new TableMigrationRenderer())->render($table, 'pgsql');
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
