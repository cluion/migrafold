<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

use Cluion\Migrafold\Analysis\Exception\MigrationAnalysisFailed;
use Cluion\Migrafold\Discovery\MigrationCatalog;

final readonly class MigrationCompactionScope
{
    public MigrationCatalog $compacted;

    public function __construct(
        public MigrationCatalog $catalog,
        public MigrationAnalysisReport $analysis,
    ) {
        $catalogMigrations = [];

        foreach ($catalog->migrations as $migration) {
            $catalogMigrations[strtolower($migration->name)] = $migration;
        }

        $compacted = [];

        foreach ($analysis->entries as $entry) {
            $key = strtolower($entry->migration->name);
            $migration = $catalogMigrations[$key] ?? null;

            if ($migration !== $entry->migration) {
                throw MigrationAnalysisFailed::because(
                    "analysis scope does not match migration [{$entry->migration->name}].",
                );
            }

            unset($catalogMigrations[$key]);

            if ($entry->analysis->action() === MigrationCompactionAction::Compact) {
                $compacted[] = $migration;
            }
        }

        if ($catalogMigrations !== []) {
            throw MigrationAnalysisFailed::because(
                'analysis scope does not cover every discovered migration.',
            );
        }

        if ($compacted === []) {
            throw MigrationAnalysisFailed::because(
                'catalog contains no schema-only migrations eligible for compaction.',
            );
        }

        $this->compacted = new MigrationCatalog($catalog->owners, $compacted);
    }
}
