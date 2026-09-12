<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Analysis\MigrationAnalysisReport;
use Cluion\Migrafold\Analysis\MigrationCatalogAnalyzer;
use Cluion\Migrafold\Analysis\MigrationCompactionAction;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;

final readonly class ReplayMigrationSet
{
    /** @var list<string> */
    public array $sourcePaths;

    /** @var list<string> */
    public array $preservedPaths;

    public MigrationAnalysisReport $analysis;

    public function __construct(
        MigrationCatalog $catalog,
        MigrationCatalogAnalyzer $analyzer = new MigrationCatalogAnalyzer(),
    ) {
        $analysis = $analyzer->analyze($catalog);
        $analysis->assertReplayable();
        $paths = [];

        foreach ($catalog->migrations as $migration) {
            if (is_link($migration->absolutePath)) {
                throw ReplayVerificationFailed::because(
                    "source migration [{$migration->source->path}] became a symbolic link after discovery.",
                );
            }

            $resolved = realpath($migration->absolutePath);

            if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
                throw ReplayVerificationFailed::because(
                    "source migration [{$migration->source->path}] is no longer a readable file.",
                );
            }

            $fingerprint = hash_file('sha256', $resolved);

            if (! is_string($fingerprint) || ! hash_equals($migration->source->sha256, $fingerprint)) {
                throw ReplayVerificationFailed::because(
                    "source migration [{$migration->source->path}] changed after analysis.",
                );
            }

            $paths[strtolower($migration->name)] = $resolved;
        }

        if ($paths === []) {
            throw ReplayVerificationFailed::because('at least one source migration is required.');
        }

        $preservedPaths = [];

        foreach ($analysis->forAction(MigrationCompactionAction::Preserve) as $entry) {
            $path = $paths[strtolower($entry->migration->name)] ?? null;

            if ($path === null) {
                throw ReplayVerificationFailed::because(
                    "preserved migration [{$entry->migration->name}] was not found in replay scope.",
                );
            }

            $preservedPaths[] = $path;
        }

        $this->sourcePaths = array_values($paths);
        $this->preservedPaths = $preservedPaths;
        $this->analysis = $analysis;
    }
}
