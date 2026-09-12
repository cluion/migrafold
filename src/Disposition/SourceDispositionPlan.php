<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Disposition;

final readonly class SourceDispositionPlan
{
    /**
     * @param list<PlannedSourceDisposition> $items
     * @param list<ProtectedBaselineFile> $protectedFiles
     */
    public function __construct(
        public string $projectRoot,
        public SourceDispositionMode $mode,
        public array $items,
        public array $protectedFiles,
    ) {}
}
