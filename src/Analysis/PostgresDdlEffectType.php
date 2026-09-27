<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

enum PostgresDdlEffectType: string
{
    case AddCheckConstraint = 'add_check_constraint';
    case AddForeignKeyConstraint = 'add_foreign_key_constraint';
    case AddGeneratedTsvectorColumn = 'add_generated_tsvector_column';
    case CreateIndex = 'create_index';
    case DropColumnNotNull = 'drop_column_not_null';
    case DropConstraint = 'drop_constraint';
    case RenameConstraint = 'rename_constraint';
    case SetColumnNotNull = 'set_column_not_null';
    case ValidateConstraint = 'validate_constraint';
}
