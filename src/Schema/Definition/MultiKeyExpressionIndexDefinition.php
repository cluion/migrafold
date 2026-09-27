<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

final readonly class MultiKeyExpressionIndexDefinition
{
    /** @param list<string> $keys */
    public function __construct(
        public string $name,
        public array $keys,
        public bool $unique,
    ) {}

    /** @return array{name: string, keys: list<string>, unique: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'keys' => $this->keys,
            'unique' => $this->unique,
        ];
    }
}
