<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

enum MigrationCompactionAction: string
{
    case Compact = 'compact';
    case Preserve = 'preserve';
    case Block = 'block';
}
