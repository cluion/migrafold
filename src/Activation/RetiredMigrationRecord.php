<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Activation;

use Cluion\Migrafold\Disposition\PlannedSourceDisposition;

final readonly class RetiredMigrationRecord
{
    public function __construct(
        public string $name,
        public PlannedSourceDisposition $source,
    ) {}
}
