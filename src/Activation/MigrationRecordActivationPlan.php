<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Activation;

use Cluion\Migrafold\Disposition\ProtectedBaselineFile;
use Cluion\Migrafold\Disposition\SourceDispositionMode;

final readonly class MigrationRecordActivationPlan
{
    /**
     * @param list<RetiredMigrationRecord> $retired
     * @param list<BaselineMigrationRecord> $baselines
     * @param list<ProtectedBaselineFile> $protectedFiles
     */
    public function __construct(
        public string $projectRoot,
        public SourceDispositionMode $disposition,
        public array $retired,
        public array $baselines,
        public array $protectedFiles,
    ) {}

    /** @return list<string> */
    public function retiredNames(): array
    {
        return array_map(
            static fn (RetiredMigrationRecord $record): string => $record->name,
            $this->retired,
        );
    }

    /** @return list<string> */
    public function baselineNames(): array
    {
        return array_map(
            static fn (BaselineMigrationRecord $record): string => $record->name,
            $this->baselines,
        );
    }
}
