<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

use Cluion\Migrafold\Discovery\MigrationCatalog;

final readonly class MigrationCatalogAnalyzer
{
    public function __construct(
        private MigrationAnalyzer $analyzer = new MigrationAnalyzer(),
    ) {}

    public function analyze(MigrationCatalog $catalog): MigrationAnalysisReport
    {
        $entries = [];

        foreach ($catalog->migrations as $migration) {
            $entries[] = new AnalyzedMigration(
                migration: $migration,
                analysis: $this->analyzer->analyze($migration),
            );
        }

        return new MigrationAnalysisReport($entries);
    }
}
