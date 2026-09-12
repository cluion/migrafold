<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Support;

use Cluion\Migrafold\Schema\Definition\ColumnDefinition;
use Cluion\Migrafold\Schema\Definition\ForeignKeyDefinition;
use Cluion\Migrafold\Schema\Definition\GeneratedColumnDefinition;
use Cluion\Migrafold\Schema\Definition\IndexDefinition;
use Cluion\Migrafold\Schema\Definition\TableDefinition;
use Illuminate\Database\Schema\Builder;
use UnexpectedValueException;

final readonly class LaravelSchemaMetadataMapper
{
    public function __construct(private string $platform) {}

    /** @param array<string, mixed> $metadata */
    public function table(
        Builder $schema,
        array $metadata,
        string $qualifiedName,
        ?string $localSchema = null,
    ): TableDefinition
    {
        $columns = array_map(
            fn (array $column): ColumnDefinition => $this->column($column),
            $schema->getColumns($qualifiedName),
        );
        $indexes = array_map(
            fn (array $index): IndexDefinition => $this->index($index),
            $schema->getIndexes($qualifiedName),
        );
        $foreignKeys = [];

        foreach ($schema->getForeignKeys($qualifiedName) as $foreignKey) {
            $foreignKeys[] = $this->foreignKey(
                $this->metadataArray($foreignKey, 'foreign-key'),
                $localSchema,
            );
        }

        usort(
            $indexes,
            static fn (IndexDefinition $left, IndexDefinition $right): int => [$left->primary ? 0 : 1, $left->name, $left->columns] <=> [$right->primary ? 0 : 1, $right->name, $right->columns],
        );
        usort(
            $foreignKeys,
            static fn (ForeignKeyDefinition $left, ForeignKeyDefinition $right): int => [$left->columns, $left->foreignTable, $left->foreignColumns, $left->name] <=> [$right->columns, $right->foreignTable, $right->foreignColumns, $right->name],
        );

        return new TableDefinition(
            name: $this->string($metadata, 'name'),
            schema: $this->localSchema(
                $this->nullableString($metadata, 'schema'),
                $localSchema,
            ),
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
            throw new UnexpectedValueException("{$this->platform} returned invalid generated-column metadata.");
        }

        $default = $metadata['default'] ?? null;

        if (! is_bool($default) && ! is_float($default) && ! is_int($default) && ! is_string($default) && $default !== null) {
            throw new UnexpectedValueException("{$this->platform} returned an unsupported column-default value.");
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
    private function foreignKey(array $metadata, ?string $localSchema): ForeignKeyDefinition
    {
        return new ForeignKeyDefinition(
            name: $this->nullableString($metadata, 'name'),
            columns: $this->stringList($metadata, 'columns'),
            foreignSchema: $this->localSchema(
                $this->nullableString($metadata, 'foreign_schema'),
                $localSchema,
            ),
            foreignTable: $this->string($metadata, 'foreign_table'),
            foreignColumns: $this->stringList($metadata, 'foreign_columns'),
            onUpdate: $this->nullableString($metadata, 'on_update'),
            onDelete: $this->nullableString($metadata, 'on_delete'),
        );
    }

    private function localSchema(?string $schema, ?string $localSchema): ?string
    {
        if ($schema !== null && $localSchema !== null && strcasecmp($schema, $localSchema) === 0) {
            return null;
        }

        return $schema;
    }

    /** @param array<array-key, mixed> $metadata */
    private function string(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException("{$this->platform} metadata [{$key}] must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $metadata */
    private function nullableString(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new UnexpectedValueException("{$this->platform} metadata [{$key}] must be a string or null.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $metadata */
    private function boolean(array $metadata, string $key): bool
    {
        $value = $metadata[$key] ?? null;

        if (! is_bool($value)) {
            throw new UnexpectedValueException("{$this->platform} metadata [{$key}] must be a boolean.");
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
            throw new UnexpectedValueException("{$this->platform} metadata [{$key}] must be a list.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new UnexpectedValueException("{$this->platform} metadata [{$key}] must contain non-empty strings.");
            }
        }

        return $value;
    }

    /** @return array<array-key, mixed> */
    private function metadataArray(mixed $metadata, string $kind): array
    {
        if (! is_array($metadata)) {
            throw new UnexpectedValueException("{$this->platform} returned invalid {$kind} metadata.");
        }

        return $metadata;
    }
}
