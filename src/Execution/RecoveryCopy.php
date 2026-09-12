<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Execution;

use Cluion\Migrafold\Disposition\PlannedSourceDisposition;

final readonly class RecoveryCopy
{
    public function __construct(
        public PlannedSourceDisposition $source,
        public string $path,
    ) {}
}
