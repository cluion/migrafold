<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

use Cluion\Migrafold\Schema\Support\PostgresExpressionNormalizer;

final readonly class CheckConstraintDefinition
{
    public function __construct(
        public string $name,
        public string $expression,
    ) {}

    /** @return array{name: string, expression: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'expression' => PostgresExpressionNormalizer::normalize($this->expression),
        ];
    }
}
