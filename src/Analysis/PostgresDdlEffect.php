<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

final readonly class PostgresDdlEffect
{
    public function __construct(
        public PostgresDdlEffectType $type,
        public string $table,
        public string $object,
        public int $line,
        public ?string $targetObject = null,
    ) {}

    public function withLine(int $line): self
    {
        return new self($this->type, $this->table, $this->object, $line, $this->targetObject);
    }
}
