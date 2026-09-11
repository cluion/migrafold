<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema;

use Cluion\Migrafold\Contracts\SchemaInspector;
use Cluion\Migrafold\Schema\Definition\CapabilityReport;
use Cluion\Migrafold\Schema\Definition\ColumnDefinition;
use Cluion\Migrafold\Schema\Definition\ForeignKeyDefinition;
use Cluion\Migrafold\Schema\Definition\GeneratedColumnDefinition;
use Cluion\Migrafold\Schema\Definition\IndexDefinition;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use Cluion\Migrafold\Schema\Definition\TableDefinition;
use Cluion\Migrafold\Schema\Exception\UnsupportedDatabaseDriver;
use Cluion\Migrafold\Schema\Exception\UnsupportedSchemaFeature;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use UnexpectedValueException;

final class SqliteSchemaInspector implements SchemaInspector
{
    /**
     * @param list<string> $excludedTables
     */
    public function inspect(Connection $connection, array $excludedTables = ['migrations']): SchemaSnapshot
    {
        if ($connection->getDriverName() !== 'sqlite') {
            throw UnsupportedDatabaseDriver::forDriver($connection->getDriverName());
        }

        $schema = $connection->getSchemaBuilder();

        $this->assertNoUnsupportedObjects($connection, $schema);

        $excluded = array_fill_keys(array_map('strtolower', $excludedTables), true);
        $tables = [];

        foreach ($schema->getTables() as $metadata) {
            $name = $this->string($metadata, 'name');

            if (isset($excluded[strtolower($name)])) {
                continue;
            }

            $tables[] = $this->table($schema, $metadata, $name);
        }

        usort(
            $tables,
            static fn (TableDefinition $left, TableDefinition $right): int => [$left->schema, $left->name] <=> [$right->schema, $right->name],
        );

        return new SchemaSnapshot(
            formatVersion: '1',
            driver: 'sqlite',
            capabilities: new CapabilityReport(
                supported: [
                    'column_collation',
                    'column_defaults',
                    'columns',
                    'foreign_key_actions',
                    'foreign_keys',
                    'generated_columns',
                    'indexes',
                    'primary_keys',
                    'unique_indexes',
                ],
                unsupported: [
                    'check_constraints',
                    'column_comments',
                    'expression_indexes',
                    'index_types',
                    'named_foreign_keys',
                    'partial_indexes',
                    'strict_tables',
                    'table_collation',
                    'table_comments',
                    'table_engine',
                    'triggers',
                    'views',
                    'virtual_tables',
                    'without_rowid',
                ],
            ),
            tables: $tables,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function table(Builder $schema, array $metadata, string $name): TableDefinition
    {
        $columns = array_map(
            fn (array $column): ColumnDefinition => $this->column($column),
            $schema->getColumns($name),
        );
        $indexes = array_map(
            fn (array $index): IndexDefinition => $this->index($index),
            $schema->getIndexes($name),
        );
        $foreignKeys = [];

        foreach ($schema->getForeignKeys($name) as $foreignKey) {
            $foreignKeys[] = $this->foreignKey(
                $this->metadataArray($foreignKey, 'foreign-key'),
            );
        }

        usort(
            $indexes,
            static fn (IndexDefinition $left, IndexDefinition $right): int => [$left->primary ? 0 : 1, $left->name, $left->columns] <=> [$right->primary ? 0 : 1, $right->name, $right->columns],
        );
        usort(
            $foreignKeys,
            static fn (ForeignKeyDefinition $left, ForeignKeyDefinition $right): int => [$left->columns, $left->foreignTable, $left->foreignColumns] <=> [$right->columns, $right->foreignTable, $right->foreignColumns],
        );

        return new TableDefinition(
            name: $name,
            schema: $this->nullableString($metadata, 'schema'),
            collation: $this->nullableString($metadata, 'collation'),
            engine: $this->nullableString($metadata, 'engine'),
            comment: $this->nullableString($metadata, 'comment'),
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function column(array $metadata): ColumnDefinition
    {
        $generation = $metadata['generation'] ?? null;

        if ($generation !== null && ! is_array($generation)) {
            throw new UnexpectedValueException('SQLite returned invalid generated-column metadata.');
        }

        $default = $metadata['default'] ?? null;

        if (! is_bool($default) && ! is_float($default) && ! is_int($default) && ! is_string($default) && $default !== null) {
            throw new UnexpectedValueException('SQLite returned an unsupported column-default value.');
        }

        return new ColumnDefinition(
            name: $this->string($metadata, 'name'),
            type: $this->string($metadata, 'type'),
            typeName: $this->string($metadata, 'type_name'),
            nullable: $this->boolean($metadata, 'nullable'),
            default: $default,
            autoIncrement: $this->boolean($metadata, 'auto_increment'),
            collation: $this->nullableString($metadata, 'collation'),
            comment: $this->nullableString($metadata, 'comment'),
            generation: $generation === null ? null : new GeneratedColumnDefinition(
                type: $this->string($generation, 'type'),
                expression: $this->nullableString($generation, 'expression'),
            ),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function index(array $metadata): IndexDefinition
    {
        return new IndexDefinition(
            name: $this->string($metadata, 'name'),
            columns: $this->stringList($metadata, 'columns'),
            type: $this->nullableString($metadata, 'type'),
            unique: $this->boolean($metadata, 'unique'),
            primary: $this->boolean($metadata, 'primary'),
        );
    }

    /** @param array<array-key, mixed> $metadata */
    private function foreignKey(array $metadata): ForeignKeyDefinition
    {
        return new ForeignKeyDefinition(
            name: $this->nullableString($metadata, 'name'),
            columns: $this->stringList($metadata, 'columns'),
            foreignSchema: $this->nullableString($metadata, 'foreign_schema'),
            foreignTable: $this->string($metadata, 'foreign_table'),
            foreignColumns: $this->stringList($metadata, 'foreign_columns'),
            onUpdate: $this->nullableString($metadata, 'on_update'),
            onDelete: $this->nullableString($metadata, 'on_delete'),
        );
    }

    private function assertNoUnsupportedObjects(Connection $connection, Builder $schema): void
    {
        $views = $schema->getViews();

        if ($views !== []) {
            throw UnsupportedSchemaFeature::detected('view', $this->string($views[0], 'schema_qualified_name'));
        }

        $objects = $connection->select(<<<'SQL'
select type, name, sql
from sqlite_schema
where sql is not null
  and type in ('index', 'table', 'trigger')
order by type, name
SQL);

        foreach ($objects as $object) {
            $metadata = (array) $object;
            $type = $this->string($metadata, 'type');
            $name = $this->string($metadata, 'name');
            $sql = $this->string($metadata, 'sql');

            if ($type === 'trigger') {
                throw UnsupportedSchemaFeature::detected('trigger', $name);
            }

            if ($type === 'table') {
                $this->assertSupportedTableSql($name, $sql);
            }

            if ($type === 'index' && preg_match('/\swhere\s/is', $sql) === 1) {
                throw UnsupportedSchemaFeature::detected('partial_index', $name);
            }
        }

        $expressionIndexes = $connection->select(<<<'SQL'
select distinct indexes.name
from sqlite_schema as tables
join pragma_index_list(tables.name) as indexes
join pragma_index_xinfo(indexes.name) as columns
where tables.type = 'table'
  and columns.key = 1
  and columns.cid = -2
order by indexes.name
SQL);

        if ($expressionIndexes !== []) {
            throw UnsupportedSchemaFeature::detected(
                'expression_index',
                $this->string((array) $expressionIndexes[0], 'name'),
            );
        }
    }

    private function assertSupportedTableSql(string $name, string $sql): void
    {
        $patterns = [
            'check_constraint' => '/\bcheck\s*\(/i',
            'strict_table' => '/\)\s*strict\s*$/i',
            'virtual_table' => '/^\s*create\s+virtual\s+table\b/i',
            'without_rowid' => '/\bwithout\s+rowid\b/i',
        ];

        foreach ($patterns as $feature => $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                throw UnsupportedSchemaFeature::detected($feature, $name);
            }
        }
    }

    /** @param array<array-key, mixed> $metadata */
    private function string(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException("SQLite metadata [{$key}] must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $metadata */
    private function nullableString(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new UnexpectedValueException("SQLite metadata [{$key}] must be a string or null.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $metadata */
    private function boolean(array $metadata, string $key): bool
    {
        $value = $metadata[$key] ?? null;

        if (! is_bool($value)) {
            throw new UnexpectedValueException("SQLite metadata [{$key}] must be a boolean.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $metadata
     * @return list<string>
     */
    private function stringList(array $metadata, string $key): array
    {
        $value = $metadata[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException("SQLite metadata [{$key}] must be a list.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new UnexpectedValueException("SQLite metadata [{$key}] must contain non-empty strings.");
            }
        }

        return $value;
    }

    /** @return array<array-key, mixed> */
    private function metadataArray(mixed $metadata, string $kind): array
    {
        if (! is_array($metadata)) {
            throw new UnexpectedValueException("SQLite returned invalid {$kind} metadata.");
        }

        return $metadata;
    }
}
