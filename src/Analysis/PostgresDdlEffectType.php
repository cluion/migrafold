<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

enum PostgresDdlEffectType: string
{
    case AddCheckConstraint = 'add_check_constraint';
    case CreateIndex = 'create_index';
    case DropColumnNotNull = 'drop_column_not_null';
    case DropConstraint = 'drop_constraint';
    case RenameConstraint = 'rename_constraint';
    case SetColumnNotNull = 'set_column_not_null';
    case ValidateConstraint = 'validate_constraint';
}
