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
        $mapper = new LaravelSchemaMetadataMapper('SQLite');
        $tables = [];

        foreach ($schema->getTables() as $metadata) {
            $name = $this->string($metadata, 'name');

            if (isset($excluded[strtolower($name)])) {
                continue;
            }

            $tables[] = $mapper->table($schema, $metadata, $name);
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

}
