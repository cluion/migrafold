<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

final readonly class OwnerOutputPlan
{
    public function __construct(
        public string $ownerId,
        public string $ownerName,
        public BaselineOutputPlan $output,
    ) {}
}
