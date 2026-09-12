<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;

final readonly class ReplayVerificationResult
{
    /**
     * @param list<GeneratedMigration> $baselines
     */
    public function __construct(
        public SchemaSnapshot $source,
        public SchemaSnapshot $baseline,
        public array $baselines,
        public int $sourceMigrations,
        public int $baselineMigrations,
    ) {}

    public function fingerprint(): string
    {
        return $this->source->fingerprint();
    }
}
