<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

final readonly class OutputWriteResult
{
    /** @param list<string> $paths */
    public function __construct(
        public OutputMode $mode,
        public array $paths,
    ) {}

    public function written(): bool
    {
        return $this->mode === OutputMode::Write;
    }
}
