<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema;

use Cluion\Migrafold\Contracts\SchemaInspector;
use Cluion\Migrafold\Schema\Definition\CapabilityReport;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use Cluion\Migrafold\Schema\Definition\TableDefinition;
use Cluion\Migrafold\Schema\Exception\UnsupportedDatabaseDriver;
use Cluion\Migrafold\Schema\Exception\UnsupportedSchemaFeature;
use Cluion\Migrafold\Schema\Support\LaravelSchemaMetadataMapper;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Builder;
use UnexpectedValueException;

final class MySqlSchemaInspector implements SchemaInspector
{
    /**
     * @param list<string> $excludedTables
     */
    public function inspect(Connection $connection, array $excludedTables = ['migrations']): SchemaSnapshot
    {
        $driver = $connection->getDriverName();

        if (! $connection instanceof MySqlConnection || ! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw UnsupportedDatabaseDriver::forInspector($driver, 'MySQL/MariaDB');
        }

        $database = $connection->getDatabaseName();

        if ($database === '' || str_contains($database, '.')) {
            throw new UnexpectedValueException('MySQL/MariaDB connection must select one database.');
        }

        $schema = $connection->getSchemaBuilder();

        $this->assertNoUnsupportedObjects($connection, $schema, $database);

        $excluded = array_fill_keys(array_map('strtolower', $excludedTables), true);
        $mapper = new LaravelSchemaMetadataMapper('MySQL/MariaDB');
        $tables = [];

        foreach ($schema->getTables($database) as $metadata) {
            $name = $this->string($metadata, 'name');

            if (isset($excluded[strtolower($name)])) {
                continue;
            }

            $table = $mapper->table($schema, $metadata, $database.'.'.$name, $database);

            foreach ($table->foreignKeys as $foreignKey) {
                if ($foreignKey->foreignSchema !== null) {
                    throw $this->unsupported(
                        $connection,
                        'cross_schema_foreign_key',
                        $table->name.'.'.($foreignKey->name ?? 'unnamed'),
                    );
                }
            }

            $tables[] = $table;
        }

        usort(
            $tables,
            static fn (TableDefinition $left, TableDefinition $right): int => [$left->schema, $left->name] <=> [$right->schema, $right->name],
        );

        return new SchemaSnapshot(
            formatVersion: '1',
            driver: $driver,
            capabilities: new CapabilityReport(
                supported: [
                    'column_collation',
                    'column_comments',
                    'column_defaults',
                    'columns',
                    'foreign_key_actions',
                    'foreign_keys',
                    'generated_columns',
                    'index_types',
                    'indexes',
                    'named_foreign_keys',
                    'primary_keys',
                    'table_collation',
                    'table_comments',
                    'table_engine',
                    'unique_indexes',
                ],
                unsupported: [
                    'check_constraints',
                    'column_on_update',
                    'cross_schema_foreign_keys',
                    'descending_indexes',
                    'expression_indexes',
                    'index_comments',
                    'index_prefix_lengths',
                    'invisible_columns',
                    'invisible_indexes',
                    'partitions',
                    'system_versioned_tables',
                    'triggers',
                    'views',
                ],
            ),
            tables: $tables,
        );
    }

    private function assertNoUnsupportedObjects(Connection $connection, Builder $schema, string $database): void
    {
        $views = $schema->getViews($database);

        if ($views !== []) {
            throw $this->unsupported($connection, 'view', $this->string($views[0], 'schema_qualified_name'));
        }

        $queries = [
            'trigger' => <<<'SQL'
select trigger_name as identity
from information_schema.triggers
where trigger_schema = ?
order by trigger_name
SQL,
            'check_constraint' => <<<'SQL'
select concat(table_name, '.', constraint_name) as identity
from information_schema.table_constraints
where constraint_schema = ? and constraint_type = 'CHECK'
order by table_name, constraint_name
SQL,
            'partition' => <<<'SQL'
select concat(table_name, '.', partition_name) as identity
from information_schema.partitions
where table_schema = ? and partition_name is not null
order by table_name, partition_ordinal_position
SQL,
            'system_versioned_table' => <<<'SQL'
select table_name as identity
from information_schema.tables
where table_schema = ? and table_type = 'SYSTEM VERSIONED'
order by table_name
SQL,
        ];

        foreach ($queries as $feature => $sql) {
            $results = $connection->select($sql, [$database]);

            if ($results !== []) {
                throw $this->unsupported(
                    $connection,
                    $feature,
                    $this->string((array) $results[0], 'identity'),
                );
            }
        }

        $columns = $connection->select(<<<'SQL'
select table_name, column_name, extra
from information_schema.columns
where table_schema = ?
order by table_name, ordinal_position
SQL, [$database]);

        foreach ($columns as $column) {
            $metadata = array_change_key_case((array) $column, CASE_LOWER);
            $extra = strtolower($this->stringOrEmpty($metadata, 'extra'));
            $identity = $this->string($metadata, 'table_name').'.'.$this->string($metadata, 'column_name');

            if (str_contains($extra, 'on update')) {
                throw $this->unsupported($connection, 'column_on_update', $identity);
            }

            if (str_contains($extra, 'invisible')) {
                throw $this->unsupported($connection, 'invisible_column', $identity);
            }
        }

        foreach ($schema->getTables($database) as $table) {
            $this->assertSupportedIndexes(
                $connection,
                $database,
                $this->string($table, 'name'),
            );
        }
    }

    private function assertSupportedIndexes(Connection $connection, string $database, string $table): void
    {
        $databaseIdentifier = $this->quoteIdentifier($database);
        $tableIdentifier = $this->quoteIdentifier($table);
        $indexes = $connection->select("show index from {$tableIdentifier} from {$databaseIdentifier}");

        foreach ($indexes as $index) {
            $metadata = array_change_key_case((array) $index, CASE_LOWER);
            $name = $this->string($metadata, 'key_name');
            $identity = $table.'.'.$name;

            if (($metadata['sub_part'] ?? null) !== null) {
                throw $this->unsupported($connection, 'index_prefix_length', $identity);
            }

            if (strtoupper($this->stringOrEmpty($metadata, 'collation')) === 'D') {
                throw $this->unsupported($connection, 'descending_index', $identity);
            }

            if ($this->stringOrEmpty($metadata, 'index_comment') !== '') {
                throw $this->unsupported($connection, 'index_comment', $identity);
            }

            if (strtoupper($this->stringOrEmpty($metadata, 'visible')) === 'NO'
                || strtoupper($this->stringOrEmpty($metadata, 'ignored')) === 'YES') {
                throw $this->unsupported($connection, 'invisible_index', $identity);
            }

            if (($metadata['column_name'] ?? null) === null
                || $this->stringOrEmpty($metadata, 'expression') !== '') {
                throw $this->unsupported($connection, 'expression_index', $identity);
            }
        }
    }

    private function unsupported(Connection $connection, string $feature, string $identity): UnsupportedSchemaFeature
    {
        return UnsupportedSchemaFeature::detectedOn($connection->getDriverTitle(), $feature, $identity);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /** @param array<array-key, mixed> $metadata */
    private function string(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException("MySQL/MariaDB metadata [{$key}] must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $metadata */
    private function stringOrEmpty(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? '';

        if (! is_string($value)) {
            throw new UnexpectedValueException("MySQL/MariaDB metadata [{$key}] must be a string.");
        }

        return $value;
    }

}
