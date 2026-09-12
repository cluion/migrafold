<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

use Cluion\Migrafold\Analysis\Exception\MigrationAnalysisFailed;
use Cluion\Migrafold\Discovery\DiscoveredMigration;

final readonly class AnalyzedMigration
{
    public function __construct(
        public DiscoveredMigration $migration,
        public MigrationAnalysis $analysis,
    ) {
        if (
            $migration->name !== $analysis->migration
            || $migration->ownerId !== $analysis->ownerId
            || $migration->source->path !== $analysis->sourcePath
        ) {
            throw MigrationAnalysisFailed::because(
                "analysis identity does not match migration [{$migration->source->path}].",
            );
        }
    }
}
