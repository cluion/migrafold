<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

final readonly class IndexDefinition
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public ?string $type,
        public bool $unique,
        public bool $primary,
    ) {}

    /**
     * @return array{name: string, columns: list<string>, type: string|null, unique: bool, primary: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'type' => $this->type,
            'unique' => $this->unique,
            'primary' => $this->primary,
        ];
    }
}
