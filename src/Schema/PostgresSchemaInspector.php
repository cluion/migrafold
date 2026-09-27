<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema;

use Cluion\Migrafold\Contracts\SchemaInspector;
use Cluion\Migrafold\Schema\Definition\CapabilityReport;
use Cluion\Migrafold\Schema\Definition\CheckConstraintDefinition;
use Cluion\Migrafold\Schema\Definition\ColumnDefinition;
use Cluion\Migrafold\Schema\Definition\ExpressionIndexDefinition;
use Cluion\Migrafold\Schema\Definition\ForeignKeyDefinition;
use Cluion\Migrafold\Schema\Definition\GinIndexDefinition;
use Cluion\Migrafold\Schema\Definition\IndexDefinition;
use Cluion\Migrafold\Schema\Definition\MultiKeyExpressionIndexDefinition;
use Cluion\Migrafold\Schema\Definition\PartialIndexDefinition;
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

        $specialIndexes = $this->assertNoUnsupportedObjects($connection, $schema);
        $hasTsvectorColumns = $this->assertSupportedTsvectorColumns($connection);
        $checkConstraints = $this->checkConstraints($connection);
        $foreignKeyModes = $this->foreignKeyModes($connection);

        $excluded = array_fill_keys(array_map('strtolower', $excludedTables), true);
        $mapper = new LaravelSchemaMetadataMapper('PostgreSQL');
        $tables = [];
        $hasChecks = false;
        $hasExpressionIndexes = false;
        $hasPartialIndexes = false;
        $hasGinIndexes = false;
        $hasDeferrableForeignKeys = false;

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
            $checks = $checkConstraints[$name] ?? [];
            $expressionIndexes = $specialIndexes['expression'][$name] ?? [];
            $multiKeyExpressionIndexes = $specialIndexes['multi_expression'][$name] ?? [];
            $partialIndexes = $specialIndexes['partial'][$name] ?? [];
            $ginIndexes = $specialIndexes['gin'][$name] ?? [];
            $tableForeignKeyModes = $foreignKeyModes[$name] ?? [];
            $hasChecks = $hasChecks || $checks !== [];
            $hasExpressionIndexes = $hasExpressionIndexes || $expressionIndexes !== [] || $multiKeyExpressionIndexes !== [];
            $hasPartialIndexes = $hasPartialIndexes || $partialIndexes !== [];
            $hasGinIndexes = $hasGinIndexes || $ginIndexes !== [];

            foreach ($tableForeignKeyModes as $mode) {
                $hasDeferrableForeignKeys = $hasDeferrableForeignKeys || $mode['deferrable'];
            }

            $tables[] = $this->normalizeTable(
                $connection,
                $table,
                $checks,
                $expressionIndexes,
                $multiKeyExpressionIndexes,
                $partialIndexes,
                $ginIndexes,
                $tableForeignKeyModes,
            );
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
                    ...($hasChecks ? ['check_constraints'] : []),
                    'column_collation',
                    'column_comments',
                    'column_defaults',
                    'columns',
                    ...($hasDeferrableForeignKeys ? ['deferrable_foreign_keys'] : []),
                    ...($hasExpressionIndexes ? ['expression_indexes'] : []),
                    'foreign_key_actions',
                    'foreign_keys',
                    'generated_columns',
                    ...($hasGinIndexes ? ['gin_indexes'] : []),
                    'indexes',
                    'named_foreign_keys',
                    ...($hasPartialIndexes ? ['partial_indexes'] : []),
                    'primary_keys',
                    'table_comments',
                    ...($hasTsvectorColumns ? ['tsvector_columns'] : []),
                    'unique_indexes',
                ],
                unsupported: [
                    'array_columns',
                    ...($hasChecks ? [] : ['check_constraints']),
                    'column_default_expressions',
                    'column_types',
                    'cross_schema_foreign_keys',
                    'custom_types',
                    ...($hasDeferrableForeignKeys
                        ? ['deferrable_primary_or_unique_constraints']
                        : ['deferrable_constraints']),
                    'exclusion_constraints',
                    ...($hasExpressionIndexes ? [] : ['expression_indexes']),
                    'identity_columns',
                    'included_index_columns',
                    'index_operator_classes',
                    'index_nulls_not_distinct',
                    'index_ordering',
                    'invalid_indexes',
                    'materialized_views',
                    'multi_schema_objects',
                    'non_btree_indexes',
                    ...($hasPartialIndexes ? [] : ['partial_indexes']),
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

    /** @return array{expression: array<string, list<ExpressionIndexDefinition>>, multi_expression: array<string, list<MultiKeyExpressionIndexDefinition>>, partial: array<string, list<PartialIndexDefinition>>, gin: array<string, list<GinIndexDefinition>>} */
    private function assertNoUnsupportedObjects(PostgresConnection $connection, PostgresBuilder $schema): array
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
where n.nspname = 'public' and k.contype in ('p', 'u') and k.condeferrable
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

        return $this->assertSupportedIndexes($connection);
    }

    /** @return array{expression: array<string, list<ExpressionIndexDefinition>>, multi_expression: array<string, list<MultiKeyExpressionIndexDefinition>>, partial: array<string, list<PartialIndexDefinition>>, gin: array<string, list<GinIndexDefinition>>} */
    private function assertSupportedIndexes(PostgresConnection $connection): array
    {
        $indexes = $connection->select(<<<'SQL'
select
    tn.nspname || '.' || tc.relname || '.' || ic.relname as identity,
    tc.relname as table_name,
    ic.relname as index_name,
    pg_get_indexdef(i.indexrelid, 1, false) as expression_key,
    to_json(array(
        select pg_get_indexdef(i.indexrelid, key_number.position, false)
        from generate_series(1, i.indnkeyatts) as key_number(position)
    ))::text as keys_json,
    pg_get_expr(i.indpred, i.indrelid, false) as predicate,
    i.indisunique as is_unique,
    i.indisprimary as is_primary,
    i.indnkeyatts as key_count,
    am.amname as access_method,
    ic.reloptions is not null as custom_storage_options,
    ic.reltablespace <> 0 as custom_tablespace,
    not i.indisready or not i.indislive as incomplete,
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
    ) as custom_operator_class,
    exists (
        select 1
        from pg_depend d
        left join pg_proc p on d.refclassid = 'pg_proc'::regclass and p.oid = d.refobjid
        left join pg_operator o on d.refclassid = 'pg_operator'::regclass and o.oid = d.refobjid
        left join pg_collation x on d.refclassid = 'pg_collation'::regclass and x.oid = d.refobjid
        left join pg_type t on d.refclassid = 'pg_type'::regclass and t.oid = d.refobjid
        where d.classid = 'pg_class'::regclass
          and d.objid = i.indexrelid
          and (
              (p.oid is not null and p.pronamespace <> 'pg_catalog'::regnamespace)
              or (o.oid is not null and o.oprnamespace <> 'pg_catalog'::regnamespace)
              or (x.oid is not null and x.collnamespace <> 'pg_catalog'::regnamespace)
              or (t.oid is not null and t.typnamespace <> 'pg_catalog'::regnamespace)
          )
    ) as external_dependency
from pg_index i
join pg_class tc on tc.oid = i.indrelid
join pg_namespace tn on tn.oid = tc.relnamespace
join pg_class ic on ic.oid = i.indexrelid
join pg_am am on am.oid = ic.relam
where tn.nspname = 'public'
order by tc.relname, ic.relname
SQL);

        $expressionIndexes = [];
        $multiKeyExpressionIndexes = [];
        $partialIndexes = [];
        $ginIndexes = [];

        foreach ($indexes as $index) {
            $metadata = array_change_key_case((array) $index, CASE_LOWER);
            $identity = $this->string($metadata, 'identity');
            $feature = match (true) {
                $this->boolean($metadata, 'invalid') => 'invalid_index',
                $this->boolean($metadata, 'incomplete') => 'invalid_index',
                $this->boolean($metadata, 'custom_storage_options') => 'index_storage_options',
                $this->boolean($metadata, 'custom_tablespace') => 'index_tablespace',
                $this->boolean($metadata, 'includes_columns') => 'included_index_columns',
                $this->boolean($metadata, 'nulls_not_distinct') => 'index_nulls_not_distinct',
                $this->boolean($metadata, 'custom_ordering') => 'index_ordering',
                $this->boolean($metadata, 'custom_operator_class') => 'index_operator_class',
                strtolower($this->string($metadata, 'access_method')) === 'gin' && (
                    $this->boolean($metadata, 'partial')
                    || $this->boolean($metadata, 'expression')
                    || $this->nonNegativeInteger($metadata, 'key_count') !== 1
                    || $this->boolean($metadata, 'is_unique')
                    || $this->boolean($metadata, 'is_primary')
                    || $this->boolean($metadata, 'external_dependency')
                ) => 'gin_index',
                ! in_array(strtolower($this->string($metadata, 'access_method')), ['btree', 'gin'], true) => 'non_btree_index',
                $this->boolean($metadata, 'partial') && (
                    $this->boolean($metadata, 'expression')
                    || $this->nonNegativeInteger($metadata, 'key_count') < 1
                    || $this->boolean($metadata, 'is_primary')
                    || $this->boolean($metadata, 'external_dependency')
                ) => 'partial_index',
                $this->boolean($metadata, 'expression') && (
                    $this->nonNegativeInteger($metadata, 'key_count') < 1
                    || $this->boolean($metadata, 'is_primary')
                    || $this->boolean($metadata, 'external_dependency')
                ) => 'expression_index',
                default => null,
            };

            if ($feature !== null) {
                throw $this->unsupported($feature, $identity);
            }

            if (strtolower($this->string($metadata, 'access_method')) === 'gin') {
                $ginIndexes[$this->string($metadata, 'table_name')][] = new GinIndexDefinition(
                    name: $this->string($metadata, 'index_name'),
                    key: $this->string($metadata, 'expression_key'),
                );
            } elseif ($this->boolean($metadata, 'partial')) {
                $partialIndexes[$this->string($metadata, 'table_name')][] = new PartialIndexDefinition(
                    name: $this->string($metadata, 'index_name'),
                    keys: $this->indexKeys($metadata, $identity, 'partial_index'),
                    predicate: $this->string($metadata, 'predicate'),
                    unique: $this->boolean($metadata, 'is_unique'),
                );
            } elseif ($this->boolean($metadata, 'expression')) {
                if ($this->nonNegativeInteger($metadata, 'key_count') === 1) {
                    $expressionIndexes[$this->string($metadata, 'table_name')][] = new ExpressionIndexDefinition(
                        name: $this->string($metadata, 'index_name'),
                        expression: $this->string($metadata, 'expression_key'),
                        unique: $this->boolean($metadata, 'is_unique'),
                    );
                } else {
                    $multiKeyExpressionIndexes[$this->string($metadata, 'table_name')][] = new MultiKeyExpressionIndexDefinition(
                        name: $this->string($metadata, 'index_name'),
                        keys: $this->indexKeys($metadata, $identity, 'expression_index'),
                        unique: $this->boolean($metadata, 'is_unique'),
                    );
                }
            }
        }

        return ['expression' => $expressionIndexes, 'multi_expression' => $multiKeyExpressionIndexes, 'partial' => $partialIndexes, 'gin' => $ginIndexes];
    }

    /**
     * @param array<array-key, mixed> $metadata
     * @return list<string>
     */
    private function indexKeys(array $metadata, string $identity, string $feature): array
    {
        $decodedKeys = json_decode($this->string($metadata, 'keys_json'), true, 512, JSON_THROW_ON_ERROR);
        $keys = [];

        if (! is_array($decodedKeys) || ! array_is_list($decodedKeys)) {
            throw $this->unsupported($feature, $identity);
        }

        foreach ($decodedKeys as $key) {
            if (! is_string($key) || trim($key) === '' || str_contains($key, "\0")) {
                throw $this->unsupported($feature, $identity);
            }

            $keys[] = $key;
        }

        if ($keys === [] || count($keys) !== $this->nonNegativeInteger($metadata, 'key_count')) {
            throw $this->unsupported($feature, $identity);
        }

        return $keys;
    }

    private function assertSupportedTsvectorColumns(PostgresConnection $connection): bool
    {
        $rows = $connection->select(<<<'SQL'
select
    c.relname as table_name,
    a.attname as column_name,
    a.attgenerated as generation_type,
    pg_get_expr(ad.adbin, ad.adrelid, false) as generation_expression,
    exists (
        select 1
        from pg_depend d
        left join pg_proc p on d.refclassid = 'pg_proc'::regclass and p.oid = d.refobjid
        left join pg_operator o on d.refclassid = 'pg_operator'::regclass and o.oid = d.refobjid
        left join pg_collation x on d.refclassid = 'pg_collation'::regclass and x.oid = d.refobjid
        left join pg_type t on d.refclassid = 'pg_type'::regclass and t.oid = d.refobjid
        left join pg_ts_config cfg on d.refclassid = 'pg_ts_config'::regclass and cfg.oid = d.refobjid
        where d.classid = 'pg_attrdef'::regclass
          and d.objid = ad.oid
          and (
              (p.oid is not null and p.pronamespace <> 'pg_catalog'::regnamespace)
              or (o.oid is not null and o.oprnamespace <> 'pg_catalog'::regnamespace)
              or (x.oid is not null and x.collnamespace <> 'pg_catalog'::regnamespace)
              or (t.oid is not null and t.typnamespace <> 'pg_catalog'::regnamespace)
              or (cfg.oid is not null and cfg.cfgnamespace <> 'pg_catalog'::regnamespace)
          )
    ) as external_dependency
from pg_attribute a
join pg_class c on c.oid = a.attrelid
join pg_namespace n on n.oid = c.relnamespace
left join pg_attrdef ad on ad.adrelid = a.attrelid and ad.adnum = a.attnum
where n.nspname = 'public'
  and c.relkind in ('r', 'p')
  and a.attnum > 0
  and not a.attisdropped
  and a.atttypid = 'pg_catalog.tsvector'::regtype
order by c.relname, a.attnum
SQL);

        foreach ($rows as $row) {
            $metadata = array_change_key_case((array) $row, CASE_LOWER);
            $identity = $this->string($metadata, 'table_name').'.'.$this->string($metadata, 'column_name');

            $generationType = $metadata['generation_type'] ?? null;

            if (! is_string($generationType) || $generationType !== 's') {
                throw $this->unsupported('tsvector_column', $identity);
            }

            $expression = $this->string($metadata, 'generation_expression');

            if (str_contains($expression, "\0") || $this->boolean($metadata, 'external_dependency')) {
                throw $this->unsupported('tsvector_column', $identity);
            }
        }

        return $rows !== [];
    }

    /** @return array<string, list<CheckConstraintDefinition>> */
    private function checkConstraints(PostgresConnection $connection): array
    {
        $rows = $connection->select(<<<'SQL'
select
    c.relname as table_name,
    k.conname as name,
    pg_get_expr(k.conbin, k.conrelid, false) as expression,
    k.convalidated as validated,
    k.connoinherit as no_inherit,
    k.coninhcount as inherited,
    exists (
        select 1
        from pg_depend d
        left join pg_proc p on d.refclassid = 'pg_proc'::regclass and p.oid = d.refobjid
        left join pg_operator o on d.refclassid = 'pg_operator'::regclass and o.oid = d.refobjid
        left join pg_collation x on d.refclassid = 'pg_collation'::regclass and x.oid = d.refobjid
        left join pg_type t on d.refclassid = 'pg_type'::regclass and t.oid = d.refobjid
        where d.classid = 'pg_constraint'::regclass
          and d.objid = k.oid
          and (
              (p.oid is not null and p.pronamespace <> 'pg_catalog'::regnamespace)
              or (o.oid is not null and o.oprnamespace <> 'pg_catalog'::regnamespace)
              or (x.oid is not null and x.collnamespace <> 'pg_catalog'::regnamespace)
              or (t.oid is not null and t.typnamespace <> 'pg_catalog'::regnamespace)
          )
    ) as external_dependency
from pg_constraint k
join pg_class c on c.oid = k.conrelid
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and k.contype = 'c'
order by c.relname, k.conname
SQL);

        $checks = [];

        foreach ($rows as $row) {
            $metadata = array_change_key_case((array) $row, CASE_LOWER);
            $table = $this->string($metadata, 'table_name');
            $name = $this->string($metadata, 'name');
            $identity = self::SCHEMA.'.'.$table.'.'.$name;

            if (! $this->boolean($metadata, 'validated')) {
                throw $this->unsupported('unvalidated_constraint', $identity);
            }

            if ($this->boolean($metadata, 'no_inherit') || $this->nonNegativeInteger($metadata, 'inherited') !== 0) {
                throw $this->unsupported('inherited_check_constraint', $identity);
            }

            if ($this->boolean($metadata, 'external_dependency')) {
                throw $this->unsupported('check_constraint_dependency', $identity);
            }

            $checks[$table][] = new CheckConstraintDefinition(
                name: $name,
                expression: $this->string($metadata, 'expression'),
            );
        }

        return $checks;
    }

    /** @return array<string, array<string, array{deferrable: bool, initially_deferred: bool}>> */
    private function foreignKeyModes(PostgresConnection $connection): array
    {
        $rows = $connection->select(<<<'SQL'
select
    c.relname as table_name,
    k.conname as name,
    k.condeferrable as deferrable,
    k.condeferred as initially_deferred
from pg_constraint k
join pg_class c on c.oid = k.conrelid
join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and k.contype = 'f'
order by c.relname, k.conname
SQL);

        $modes = [];

        foreach ($rows as $row) {
            $metadata = array_change_key_case((array) $row, CASE_LOWER);
            $table = $this->string($metadata, 'table_name');
            $name = $this->string($metadata, 'name');
            $deferrable = $this->boolean($metadata, 'deferrable');
            $initiallyDeferred = $this->boolean($metadata, 'initially_deferred');

            if ($initiallyDeferred && ! $deferrable) {
                throw $this->unsupported(
                    'foreign_key_deferrability',
                    self::SCHEMA.'.'.$table.'.'.$name,
                );
            }

            $modes[$table][$name] = [
                'deferrable' => $deferrable,
                'initially_deferred' => $initiallyDeferred,
            ];
        }

        return $modes;
    }

    /**
     * @param list<CheckConstraintDefinition> $checks
     * @param list<ExpressionIndexDefinition> $expressionIndexes
     * @param list<MultiKeyExpressionIndexDefinition> $multiKeyExpressionIndexes
     * @param list<PartialIndexDefinition> $partialIndexes
     * @param list<GinIndexDefinition> $ginIndexes
     * @param array<string, array{deferrable: bool, initially_deferred: bool}> $foreignKeyModes
     */
    private function normalizeTable(PostgresConnection $connection, TableDefinition $table, array $checks, array $expressionIndexes, array $multiKeyExpressionIndexes, array $partialIndexes, array $ginIndexes, array $foreignKeyModes): TableDefinition
    {
        $specialIndexNames = array_fill_keys([
            ...array_column($expressionIndexes, 'name'),
            ...array_column($multiKeyExpressionIndexes, 'name'),
            ...array_column($partialIndexes, 'name'),
            ...array_column($ginIndexes, 'name'),
        ], true);
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
            indexes: array_values(array_filter(
                $table->indexes,
                static fn (IndexDefinition $index): bool => ! isset($specialIndexNames[$index->name]),
            )),
            foreignKeys: $this->normalizeForeignKeys($table, $foreignKeyModes),
            checkConstraints: $checks,
            expressionIndexes: $expressionIndexes,
            partialIndexes: $partialIndexes,
            multiKeyExpressionIndexes: $multiKeyExpressionIndexes,
            ginIndexes: $ginIndexes,
        );
    }

    /**
     * @param array<string, array{deferrable: bool, initially_deferred: bool}> $modes
     * @return list<ForeignKeyDefinition>
     */
    private function normalizeForeignKeys(TableDefinition $table, array $modes): array
    {
        $foreignKeys = [];

        foreach ($table->foreignKeys as $foreignKey) {
            $name = $foreignKey->name;

            if ($name === null || ! isset($modes[$name])) {
                throw new UnexpectedValueException(
                    'PostgreSQL foreign key metadata is incomplete for ['
                    .$table->name.'.'.($name ?? 'unnamed').'].',
                );
            }

            $mode = $modes[$name];
            unset($modes[$name]);
            $foreignKeys[] = new ForeignKeyDefinition(
                name: $foreignKey->name,
                columns: $foreignKey->columns,
                foreignSchema: $foreignKey->foreignSchema,
                foreignTable: $foreignKey->foreignTable,
                foreignColumns: $foreignKey->foreignColumns,
                onUpdate: $foreignKey->onUpdate,
                onDelete: $foreignKey->onDelete,
                deferrable: $mode['deferrable'],
                initiallyDeferred: $mode['initially_deferred'],
            );
        }

        if ($modes !== []) {
            throw new UnexpectedValueException(
                "PostgreSQL foreign key metadata contains an unmapped constraint for [{$table->name}].",
            );
        }

        return $foreignKeys;
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
            'tsvector',
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

        if (preg_match("/^'([^']+)'::(smallint|integer|bigint|numeric|decimal|real|double precision)$/i", $default, $matches) === 1
            && is_numeric($matches[1])
            && $this->numericCastMatchesType($type, strtolower($matches[2]))) {
            return $matches[1];
        }

        throw $this->unsupported('column_default_expression', $identity);
    }

    private function numericCastMatchesType(string $type, string $cast): bool
    {
        $normalizedType = preg_match('/^(?:numeric|decimal)\b/', $type) === 1 ? 'numeric' : $type;
        $normalizedCast = $cast === 'decimal' ? 'numeric' : $cast;

        return $normalizedType === $normalizedCast;
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

    /** @param array<array-key, mixed> $metadata */
    private function nonNegativeInteger(array $metadata, string $key): int
    {
        $value = $metadata[$key] ?? null;

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new UnexpectedValueException("PostgreSQL metadata [{$key}] must be a non-negative integer.");
    }
}
