<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

enum MigrationClassification: string
{
    case SchemaOnly = 'schema_only';
    case DataOnly = 'data_only';
    case Mixed = 'mixed';
    case RawSchema = 'raw_schema';
    case DynamicSchema = 'dynamic_schema';
    case NonSchema = 'non_schema';
    case Unsupported = 'unsupported';

    public function action(): MigrationCompactionAction
    {
        return match ($this) {
            self::SchemaOnly => MigrationCompactionAction::Compact,
            self::DataOnly, self::NonSchema => MigrationCompactionAction::Preserve,
            self::Mixed, self::RawSchema, self::DynamicSchema, self::Unsupported => MigrationCompactionAction::Block,
        };
    }
}
