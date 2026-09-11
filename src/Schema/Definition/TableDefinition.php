<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

final readonly class TableDefinition
{
    /**
     * @param list<ColumnDefinition> $columns
     * @param list<IndexDefinition> $indexes
     * @param list<ForeignKeyDefinition> $foreignKeys
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
     *     foreign_keys: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
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
    }
}
