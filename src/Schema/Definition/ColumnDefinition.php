<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

final readonly class ColumnDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public string $typeName,
        public bool $nullable,
        public bool|float|int|string|null $default,
        public bool $autoIncrement,
        public ?string $collation,
        public ?string $comment,
        public ?GeneratedColumnDefinition $generation,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     type: string,
     *     type_name: string,
     *     nullable: bool,
     *     default: bool|float|int|string|null,
     *     auto_increment: bool,
     *     collation: string|null,
     *     comment: string|null,
     *     generation: array{type: string, expression: string|null}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'type_name' => $this->typeName,
            'nullable' => $this->nullable,
            'default' => $this->default,
            'auto_increment' => $this->autoIncrement,
            'collation' => $this->collation,
            'comment' => $this->comment,
            'generation' => $this->generation?->toArray(),
        ];
    }
}
