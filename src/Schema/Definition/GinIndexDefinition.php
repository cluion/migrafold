<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Definition;

final readonly class GinIndexDefinition
{
    public function __construct(
        public string $name,
        public string $key,
    ) {}

    /** @return array{name: string, key: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'key' => $this->key,
        ];
    }
}
