<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema;

use Cluion\Migrafold\Contracts\SchemaInspector;
use Cluion\Migrafold\Schema\Definition\CapabilityReport;
use Cluion\Migrafold\Schema\Definition\ColumnDefinition;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use Cluion\Migrafold\Schema\Definition\TableDefinition;
use Cluion\Migrafold\Schema\Exception\UnsupportedDatabaseDriver;
use Cluion\Migrafold\Schema\Exception\UnsupportedSchemaFeature;
use Cluion\Migrafold\Schema\Support\LaravelSchemaMetadataMapper;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\PostgresBuilder;
use UnexpectedValueException;

final class PostgresSchemaInspector implements SchemaInspector
{
    private const SCHEMA = 'public';

    /**
     * @param list<string> $excludedTables
     */
    public function inspect(Connection $connection, array $excludedTables = ['migrations']): SchemaSnapshot
    {
        if (! $connection instanceof PostgresConnection || $connection->getDriverName() !== 'pgsql') {
            throw UnsupportedDatabaseDriver::forInspector($connection->getDriverName(), 'PostgreSQL');
        }

        $database = $connection->getDatabaseName();

        if ($database === '' || str_contains($database, '.')) {
            throw new UnexpectedValueException('PostgreSQL connection must select one database.');
        }

        $schema = $connection->getSchemaBuilder();

        $searchPath = $schema->getCurrentSchemaListing();

        if ($searchPath !== [self::SCHEMA]) {
            throw $this->unsupported('search_path', implode(',', $searchPath));
        }

        $this->assertNoUnsupportedObjects($connection, $schema);

        $excluded = array_fill_keys(array_map('strtolower', $excludedTables), true);
        $mapper = new LaravelSchemaMetadataMapper('PostgreSQL');
        $tables = [];

        foreach ($schema->getTables(self::SCHEMA) as $metadata) {
            $name = $this->string($metadata, 'name');

            if (isset($excluded[strtolower($name)])) {
                continue;
            }

            $table = $mapper->table(
                $schema,
                $metadata,
                self::SCHEMA.'.'.$name,
                self::SCHEMA,
            );
            $tables[] = $this->normalizeTable($connection, $table);
        }

        usort(
            $tables,
            static fn (TableDefinition $left, TableDefinition $right): int => [$left->schema, $left->name] <=> [$right->schema, $right->name],
        );

        return new SchemaSnapshot(
            formatVersion: '1',
            driver: 'pgsql',
            capabilities: new CapabilityReport(
                supported: [
                    'column_collation',
                    'column_comments',
                    'column_defaults',
                    'columns',
                    'foreign_key_actions',
                    'foreign_keys',
                    'generated_columns',
                    'indexes',
                    'named_foreign_keys',
                    'primary_keys',
                    'table_comments',
                    'unique_indexes',
                ],
                unsupported: [
                    'array_columns',
                    'check_constraints',
                    'column_default_expressions',
                    'column_types',
                    'cross_schema_foreign_keys',
                    'custom_types',
                    'deferrable_constraints',
                    'exclusion_constraints',
                    'expression_indexes',
                    'identity_columns',
                    'included_index_columns',
                    'index_operator_classes',
                    'index_nulls_not_distinct',
                    'index_ordering',
                    'invalid_indexes',
                    'materialized_views',
                    'multi_schema_objects',
                    'non_btree_indexes',
                    'partial_indexes',
                    'partitions',
                    'row_level_security',
                    'standalone_sequences',
                    'triggers',
                    'unlogged_tables',
                    'unowned_sequence_defaults',
                    'unvalidated_constraints',
                    'views',
                ],
            ),
            tables: $tables,
        );
    }

    private function assertNoUnsupportedObjects(PostgresConnection $connection, PostgresBuilder $schema): void
    {
        $views = $schema->getViews(self::SCHEMA);

        if ($views !== []) {
            throw $this->unsupported('view', $this->string($views[0], 'schema_qualified_name'));
        }

        $queries = [
            'custom_type' => <<<'SQL'
select n.nspname || '.' || t.typname as identity
from pg_type t
join pg_namespace n on n.oid = t.typnamespace
left join pg_class c on c.oid = t.typrelid
left join pg_type el on el.oid = t.typelem
left join pg_class ce on ce.oid = el.typrelid
where n.nspname = 'public'
  and ((t.typrelid = 0 and (ce.relkind = 'c' or ce.relkind is null)) or c.relkind = 'c')
  and not exists (
      select 1 from pg_depend d
      where d.objid in (t.oid, t.typelem) and d.deptype = 'e'
  )
  and not (
      (t.typinput = 'array_in'::regproc and t.typoutput = 'array_out'::regproc)
      or t.typtype = 'm'
  )
order by t.typname
limit 1
SQL,
            'multi_schema_object' => <<<'SQL'
select n.nspname || '.' || c.relname as identity
from pg_class c
join pg_namespace n on n.oid = c.relnamespace
where n.nspname <> 'public'
  and n.nspname <> 'information_schema'
  and n.nspname not like 'pg\_%'
  and c.relkind in ('r', 'p', 'v', 'm', 'S')
order by n.nspname, c.relname
limit 1
SQL,
            'materialized_view' => <<<'SQL'
select n.nspname || '.' || c.relname as identity
from pg_class c
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and c.relkind = 'm'
order by c.relname
limit 1
SQL,
            'trigger' => <<<'SQL'
select n.nspname || '.' || c.relname || '.' || t.tgname as identity
from pg_trigger t
join pg_class c on c.oid = t.tgrelid
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and not t.tgisinternal
order by c.relname, t.tgname
limit 1
SQL,
            'check_constraint' => <<<'SQL'
select n.nspname || '.' || c.relname || '.' || k.conname as identity
from pg_constraint k
join pg_class c on c.oid = k.conrelid
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and k.contype = 'c'
order by c.relname, k.conname
limit 1
SQL,
            'partition' => <<<'SQL'
select n.nspname || '.' || c.relname as identity
from pg_class c
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and (c.relkind = 'p' or c.relispartition)
order by c.relname
limit 1
SQL,
            'unlogged_table' => <<<'SQL'
select n.nspname || '.' || c.relname as identity
from pg_class c
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and c.relkind = 'r' and c.relpersistence <> 'p'
order by c.relname
limit 1
SQL,
            'row_level_security' => <<<'SQL'
select n.nspname || '.' || c.relname as identity
from pg_class c
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and (c.relrowsecurity or c.relforcerowsecurity)
order by c.relname
limit 1
SQL,
            'identity_column' => <<<'SQL'
select n.nspname || '.' || c.relname || '.' || a.attname as identity
from pg_attribute a
join pg_class c on c.oid = a.attrelid
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and a.attnum > 0 and not a.attisdropped and a.attidentity <> ''
order by c.relname, a.attnum
limit 1
SQL,
            'array_column' => <<<'SQL'
select n.nspname || '.' || c.relname || '.' || a.attname as identity
from pg_attribute a
join pg_class c on c.oid = a.attrelid
join pg_namespace n on n.oid = c.relnamespace
join pg_type t on t.oid = a.atttypid
where n.nspname = 'public'
  and c.relkind in ('r', 'p')
  and a.attnum > 0
  and not a.attisdropped
  and (a.attndims > 0 or t.typcategory = 'A')
order by c.relname, a.attnum
limit 1
SQL,
            'deferrable_constraint' => <<<'SQL'
select n.nspname || '.' || c.relname || '.' || k.conname as identity
from pg_constraint k
join pg_class c on c.oid = k.conrelid
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and k.contype in ('p', 'u', 'f') and k.condeferrable
order by c.relname, k.conname
limit 1
SQL,
            'unvalidated_constraint' => <<<'SQL'
select n.nspname || '.' || c.relname || '.' || k.conname as identity
from pg_constraint k
join pg_class c on c.oid = k.conrelid
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and not k.convalidated
order by c.relname, k.conname
limit 1
SQL,
            'exclusion_constraint' => <<<'SQL'
select n.nspname || '.' || c.relname || '.' || k.conname as identity
from pg_constraint k
join pg_class c on c.oid = k.conrelid
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and k.contype = 'x'
order by c.relname, k.conname
limit 1
SQL,
            'standalone_sequence' => <<<'SQL'
select n.nspname || '.' || c.relname as identity
from pg_class c
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public'
  and c.relkind = 'S'
  and not exists (
      select 1
      from pg_depend d
      where d.classid = 'pg_class'::regclass
        and d.objid = c.oid
        and d.refclassid = 'pg_class'::regclass
        and d.deptype in ('a', 'i')
  )
order by c.relname
limit 1
SQL,
        ];

        foreach ($queries as $feature => $sql) {
            $result = $connection->selectOne($sql);

            if ($result !== null) {
                throw $this->unsupported($feature, $this->string((array) $result, 'identity'));
            }
        }

        $this->assertSupportedIndexes($connection);
    }

    private function assertSupportedIndexes(PostgresConnection $connection): void
    {
        $indexes = $connection->select(<<<'SQL'
select
    tn.nspname || '.' || tc.relname || '.' || ic.relname as identity,
    am.amname as access_method,
    i.indpred is not null as partial,
    i.indexprs is not null as expression,
    i.indnkeyatts <> i.indnatts as includes_columns,
    i.indnullsnotdistinct as nulls_not_distinct,
    not i.indisvalid as invalid,
    exists (
        select 1
        from unnest(i.indoption::smallint[]) as option(value)
        where option.value <> 0
    ) as custom_ordering,
    exists (
        select 1
        from unnest(i.indclass::oid[]) as classes(opclass_oid)
        join pg_opclass opclass on opclass.oid = classes.opclass_oid
        where not opclass.opcdefault
    ) as custom_operator_class
from pg_index i
join pg_class tc on tc.oid = i.indrelid
join pg_namespace tn on tn.oid = tc.relnamespace
join pg_class ic on ic.oid = i.indexrelid
join pg_am am on am.oid = ic.relam
where tn.nspname = 'public'
order by tc.relname, ic.relname
SQL);

        foreach ($indexes as $index) {
            $metadata = array_change_key_case((array) $index, CASE_LOWER);
            $identity = $this->string($metadata, 'identity');
            $feature = match (true) {
                $this->boolean($metadata, 'invalid') => 'invalid_index',
                $this->boolean($metadata, 'partial') => 'partial_index',
                $this->boolean($metadata, 'expression') => 'expression_index',
                $this->boolean($metadata, 'includes_columns') => 'included_index_columns',
                $this->boolean($metadata, 'nulls_not_distinct') => 'index_nulls_not_distinct',
                $this->boolean($metadata, 'custom_ordering') => 'index_ordering',
                $this->boolean($metadata, 'custom_operator_class') => 'index_operator_class',
                strtolower($this->string($metadata, 'access_method')) !== 'btree' => 'non_btree_index',
                default => null,
            };

            if ($feature !== null) {
                throw $this->unsupported($feature, $identity);
            }
        }
    }

    private function normalizeTable(PostgresConnection $connection, TableDefinition $table): TableDefinition
    {
        $columns = array_map(
            fn (ColumnDefinition $column): ColumnDefinition => $this->normalizeColumn($connection, $table, $column),
            $table->columns,
        );

        foreach ($table->foreignKeys as $foreignKey) {
            if ($foreignKey->foreignSchema !== null) {
                throw $this->unsupported(
                    'cross_schema_foreign_key',
                    $table->name.'.'.($foreignKey->name ?? 'unnamed'),
                );
            }
        }

        return new TableDefinition(
            name: $table->name,
            schema: $table->schema,
            collation: $table->collation,
            engine: $table->engine,
            comment: $table->comment,
            columns: $columns,
            indexes: $table->indexes,
            foreignKeys: $table->foreignKeys,
        );
    }

    private function normalizeColumn(
        PostgresConnection $connection,
        TableDefinition $table,
        ColumnDefinition $column,
    ): ColumnDefinition {
        $type = $this->normalizeType($column->type, $table->name.'.'.$column->name);
        $default = $this->normalizeDefault($column, $type, $table->name.'.'.$column->name);

        if ($column->autoIncrement) {
            $sequence = $connection->scalar(
                'select pg_get_serial_sequence(?, ?)',
                [self::SCHEMA.'.'.$table->name, $column->name],
            );

            if (! is_string($sequence) || $sequence === '') {
                throw $this->unsupported('unowned_sequence_default', $table->name.'.'.$column->name);
            }
        }

        return new ColumnDefinition(
            name: $column->name,
            type: $type,
            typeName: $column->typeName,
            nullable: $column->nullable,
            default: $default,
            autoIncrement: $column->autoIncrement,
            collation: $column->collation,
            comment: $column->comment,
            generation: $column->generation,
        );
    }

    private function normalizeType(string $type, string $identity): string
    {
        $type = strtolower(trim($type));

        $patterns = [
            '/^character varying(?:\((\d+)\))?$/' => fn (array $matches): string => $this->typeWithOptionalPrecision('varchar', $matches),
            '/^character(?:\((\d+)\))?$/' => fn (array $matches): string => $this->typeWithOptionalPrecision('char', $matches),
            '/^numeric\((\d+),(\d+)\)$/' => fn (array $matches): string => 'decimal('.$this->match($matches, 1).','.$this->match($matches, 2).')',
            '/^timestamp(?:\((\d+)\))? without time zone$/' => fn (array $matches): string => $this->typeWithOptionalPrecision('timestamp', $matches),
            '/^timestamp(?:\((\d+)\))? with time zone$/' => fn (array $matches): string => $this->typeWithOptionalPrecision('timestamptz', $matches),
            '/^time(?:\((\d+)\))? without time zone$/' => fn (array $matches): string => $this->typeWithOptionalPrecision('time', $matches),
            '/^time(?:\((\d+)\))? with time zone$/' => fn (array $matches): string => $this->typeWithOptionalPrecision('timetz', $matches),
        ];

        foreach ($patterns as $pattern => $normalize) {
            if (preg_match($pattern, $type, $matches) === 1) {
                return $normalize($matches);
            }
        }

        $simple = [
            'bigint',
            'boolean',
            'date',
            'double precision',
            'integer',
            'json',
            'jsonb',
            'numeric',
            'real',
            'smallint',
            'text',
            'uuid',
            'bytea',
        ];

        if (in_array($type, $simple, true)) {
            return $type;
        }

        throw $this->unsupported('column_type', $identity.':'.$type);
    }

    /** @param array<array-key, mixed> $matches */
    private function typeWithOptionalPrecision(string $type, array $matches): string
    {
        return isset($matches[1]) ? $type.'('.$this->match($matches, 1).')' : $type;
    }

    /** @param array<array-key, mixed> $matches */
    private function match(array $matches, int $index): string
    {
        $value = $matches[$index] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException("PostgreSQL type match [{$index}] must be a non-empty string.");
        }

        return $value;
    }

    private function normalizeDefault(
        ColumnDefinition $column,
        string $type,
        string $identity,
    ): bool|float|int|string|null {
        $default = $column->default;

        if ($default === null || $column->generation !== null || $column->autoIncrement) {
            return null;
        }

        if (! is_string($default)) {
            return $default;
        }

        if ($type === 'boolean' && in_array(strtolower($default), ['true', 'false'], true)) {
            return strtolower($default) === 'true';
        }

        if (preg_match('/^(?:now\(\)|current_timestamp)$/i', $default) === 1) {
            return 'CURRENT_TIMESTAMP';
        }

        if (preg_match('/^current_(?:date|time)$/i', $default) === 1) {
            return strtoupper($default);
        }

        if (preg_match('/^\'(.*)\'::(?:character varying|character|text|uuid|jsonb?|date|timestamp(?: with(?:out)? time zone)?|time(?: with(?:out)? time zone)?)$/s', $default, $matches) === 1) {
            return "'".$matches[1]."'";
        }

        if (preg_match('/^(?:smallint|integer|bigint|numeric|decimal|real|double precision)\b/', $type) === 1
            && is_numeric($default)) {
            return $default;
        }

        throw $this->unsupported('column_default_expression', $identity);
    }

    private function unsupported(string $feature, string $identity): UnsupportedSchemaFeature
    {
        return UnsupportedSchemaFeature::detectedOn('PostgreSQL', $feature, $identity);
    }

    /** @param array<array-key, mixed> $metadata */
    private function string(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException("PostgreSQL metadata [{$key}] must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $metadata */
    private function boolean(array $metadata, string $key): bool
    {
        $value = $metadata[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === '0' || $value === 'f') {
            return false;
        }

        if ($value === 1 || $value === '1' || $value === 't') {
            return true;
        }

        throw new UnexpectedValueException("PostgreSQL metadata [{$key}] must be boolean-compatible.");
    }
}
