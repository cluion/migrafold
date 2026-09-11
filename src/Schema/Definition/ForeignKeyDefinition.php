<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

final readonly class ForeignKeyDefinition
{
    /**
     * @param list<string> $columns
     * @param list<string> $foreignColumns
     */
    public function __construct(
        public ?string $name,
        public array $columns,
        public ?string $foreignSchema,
        public string $foreignTable,
        public array $foreignColumns,
        public ?string $onUpdate,
        public ?string $onDelete,
    ) {}

    /**
     * @return array{
     *     name: string|null,
     *     columns: list<string>,
     *     foreign_schema: string|null,
     *     foreign_table: string,
     *     foreign_columns: list<string>,
     *     on_update: string|null,
     *     on_delete: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'foreign_schema' => $this->foreignSchema,
            'foreign_table' => $this->foreignTable,
            'foreign_columns' => $this->foreignColumns,
            'on_update' => $this->onUpdate,
            'on_delete' => $this->onDelete,
        ];
    }
}
