<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

enum PostgresDdlEffectType: string
{
    case AddCheckConstraint = 'add_check_constraint';
    case CreateIndex = 'create_index';
}
