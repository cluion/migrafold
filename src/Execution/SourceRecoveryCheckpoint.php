<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Execution;

final readonly class SourceRecoveryCheckpoint
{
    /**
     * @param list<RecoveryCopy> $copies
     * @param list<string> $directories
     */
    public function __construct(
        public array $copies,
        public array $directories,
    ) {}
}
