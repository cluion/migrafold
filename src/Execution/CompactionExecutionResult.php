<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Execution;

use Cluion\Migrafold\Activation\MigrationRecordActivationResult;

final readonly class CompactionExecutionResult
{
    /**
     * @param list<string> $outputPaths
     * @param list<string> $warnings
     */
    public function __construct(
        public array $outputPaths,
        public int $retiredSources,
        public MigrationRecordActivationResult $activation,
        public array $warnings,
    ) {}

    public function clean(): bool
    {
        return $this->warnings === [];
    }
}
