<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Disposition;

use Cluion\Migrafold\Output\OutputMode;

final readonly class SourceDispositionResult
{
    /** @param list<PlannedSourceDisposition> $items */
    public function __construct(
        public SourceDispositionMode $disposition,
        public OutputMode $mode,
        public array $items,
    ) {}

    public function applied(): bool
    {
        return $this->mode === OutputMode::Write;
    }
}
