<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

use Cluion\Migrafold\Schema\Support\PostgresExpressionNormalizer;

final readonly class PartialIndexDefinition
{
    /** @param list<string> $keys */
    public function __construct(
        public string $name,
        public array $keys,
        public string $predicate,
        public bool $unique,
    ) {}

    /** @return array{name: string, keys: list<string>, predicate: string, unique: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'keys' => $this->keys,
            'predicate' => PostgresExpressionNormalizer::normalize($this->predicate),
            'unique' => $this->unique,
        ];
    }
}
