<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

final readonly class ExpressionIndexDefinition
{
    public function __construct(
        public string $name,
        public string $expression,
        public bool $unique,
    ) {}

    /** @return array{name: string, expression: string, unique: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'expression' => $this->expression,
            'unique' => $this->unique,
        ];
    }
}
