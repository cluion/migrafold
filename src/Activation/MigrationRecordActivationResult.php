<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Activation;

use Cluion\Migrafold\Output\OutputMode;

final readonly class MigrationRecordActivationResult
{
    /**
     * @param list<string> $retired
     * @param list<string> $baselines
     */
    public function __construct(
        public OutputMode $mode,
        public array $retired,
        public array $baselines,
        public ?int $batch,
        public bool $alreadyActivated,
    ) {}

    public function applied(): bool
    {
        return $this->mode === OutputMode::Write && ! $this->alreadyActivated;
    }
}
