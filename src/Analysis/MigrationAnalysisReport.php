<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

use Cluion\Migrafold\Analysis\Exception\MigrationAnalysisFailed;

final readonly class MigrationAnalysisReport
{
    /** @var list<AnalyzedMigration> */
    public array $entries;

    /**
     * @param list<AnalyzedMigration> $entries
     */
    public function __construct(array $entries)
    {
        $names = [];

        foreach ($entries as $entry) {
            $key = strtolower($entry->migration->name);

            if (isset($names[$key])) {
                throw MigrationAnalysisFailed::because(
                    "analysis report contains duplicate migration [{$entry->migration->name}].",
                );
            }

            $names[$key] = true;
        }

        $this->entries = $entries;
    }

    /** @return list<AnalyzedMigration> */
    public function forAction(MigrationCompactionAction $action): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (AnalyzedMigration $entry): bool => $entry->analysis->action() === $action,
        ));
    }

    /** @return list<AnalyzedMigration> */
    public function compactable(): array
    {
        return $this->forAction(MigrationCompactionAction::Compact);
    }

    /** @return list<AnalyzedMigration> */
    public function preserved(): array
    {
        return $this->forAction(MigrationCompactionAction::Preserve);
    }

    /** @return list<AnalyzedMigration> */
    public function blocking(): array
    {
        return $this->forAction(MigrationCompactionAction::Block);
    }

    public function assertReplayable(): void
    {
        $blocking = $this->blocking();

        if ($blocking === []) {
            return;
        }

        $scope = implode(', ', array_map(
            static fn (AnalyzedMigration $entry): string => sprintf(
                '%s:%s',
                $entry->migration->name,
                $entry->analysis->classification->value,
            ),
            $blocking,
        ));

        throw MigrationAnalysisFailed::because(
            "catalog contains blocking migrations [{$scope}]; replay was not started.",
        );
    }
}
