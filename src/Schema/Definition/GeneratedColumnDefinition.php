<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

final readonly class GeneratedColumnDefinition
{
    public function __construct(
        public string $type,
        public ?string $expression,
    ) {}

    /**
     * @return array{type: string, expression: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'expression' => $this->expression,
        ];
    }
}
