<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

final readonly class TableDefinition
{
    /**
     * @param list<ColumnDefinition> $columns
     * @param list<IndexDefinition> $indexes
     * @param list<ForeignKeyDefinition> $foreignKeys
     * @param list<CheckConstraintDefinition> $checkConstraints
     * @param list<ExpressionIndexDefinition> $expressionIndexes
     * @param list<PartialIndexDefinition> $partialIndexes
     * @param list<MultiKeyExpressionIndexDefinition> $multiKeyExpressionIndexes
     * @param list<GinIndexDefinition> $ginIndexes
     */
    public function __construct(
        public string $name,
        public ?string $schema,
        public ?string $collation,
        public ?string $engine,
        public ?string $comment,
        public array $columns,
        public array $indexes,
        public array $foreignKeys,
        public array $checkConstraints = [],
        public array $expressionIndexes = [],
        public array $partialIndexes = [],
        public array $multiKeyExpressionIndexes = [],
        public array $ginIndexes = [],
    ) {}

    /**
     * @return array{
     *     name: string,
     *     schema: string|null,
     *     collation: string|null,
     *     engine: string|null,
     *     comment: string|null,
     *     columns: list<array<string, mixed>>,
     *     indexes: list<array<string, mixed>>,
     *     foreign_keys: list<array<string, mixed>>,
     *     check_constraints?: list<array{name: string, expression: string}>,
     *     expression_indexes?: list<array{name: string, expression: string, unique: bool}>,
     *     partial_indexes?: list<array{name: string, keys: list<string>, predicate: string, unique: bool}>,
     *     multi_key_expression_indexes?: list<array{name: string, keys: list<string>, unique: bool}>,
     *     gin_indexes?: list<array{name: string, key: string}>
     * }
     */
    public function toArray(): array
    {
        $table = [
            'name' => $this->name,
            'schema' => $this->schema,
            'collation' => $this->collation,
            'engine' => $this->engine,
            'comment' => $this->comment,
            'columns' => array_map(
                static fn (ColumnDefinition $column): array => $column->toArray(),
                $this->columns,
            ),
            'indexes' => array_map(
                static fn (IndexDefinition $index): array => $index->toArray(),
                $this->indexes,
            ),
            'foreign_keys' => array_map(
                static fn (ForeignKeyDefinition $foreignKey): array => $foreignKey->toArray(),
                $this->foreignKeys,
            ),
        ];

        if ($this->checkConstraints !== []) {
            $table['check_constraints'] = array_map(
                static fn (CheckConstraintDefinition $check): array => $check->toArray(),
                $this->checkConstraints,
            );
        }

        if ($this->expressionIndexes !== []) {
            $table['expression_indexes'] = array_map(
                static fn (ExpressionIndexDefinition $index): array => $index->toArray(),
                $this->expressionIndexes,
            );
        }

        if ($this->partialIndexes !== []) {
            $table['partial_indexes'] = array_map(
                static fn (PartialIndexDefinition $index): array => $index->toArray(),
                $this->partialIndexes,
            );
        }

        if ($this->multiKeyExpressionIndexes !== []) {
            $table['multi_key_expression_indexes'] = array_map(
                static fn (MultiKeyExpressionIndexDefinition $index): array => $index->toArray(),
                $this->multiKeyExpressionIndexes,
            );
        }

        if ($this->ginIndexes !== []) {
            $table['gin_indexes'] = array_map(
                static fn (GinIndexDefinition $index): array => $index->toArray(),
                $this->ginIndexes,
            );
        }

        return $table;
    }
}
