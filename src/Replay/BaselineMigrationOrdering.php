<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Analysis\AnalyzedMigration;
use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;

final readonly class BaselineMigrationOrdering
{
    /**
     * @param list<GeneratedMigration> $baselines
     * @param list<AnalyzedMigration> $preserved
     */
    public function assertBaselinesRunFirst(array $baselines, array $preserved): void
    {
        if ($preserved === []) {
            return;
        }

        if ($baselines === []) {
            throw ReplayVerificationFailed::because(
                'at least one baseline migration is required before preserved migrations.',
            );
        }

        $baselineNames = array_map(
            static fn (GeneratedMigration $migration): string => pathinfo($migration->filename, PATHINFO_FILENAME),
            $baselines,
        );
        $preservedNames = array_map(
            static fn (AnalyzedMigration $entry): string => $entry->migration->name,
            $preserved,
        );
        sort($baselineNames, SORT_STRING);
        sort($preservedNames, SORT_STRING);
        $lastBaseline = $baselineNames[count($baselineNames) - 1];
        $firstPreserved = $preservedNames[0];

        if (strcmp($lastBaseline, $firstPreserved) >= 0) {
            throw ReplayVerificationFailed::because(
                "baseline migration [{$lastBaseline}] must sort before preserved migration [{$firstPreserved}].",
            );
        }
    }
}
