<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis;

final readonly class MigrationAnalysis
{
    /** @var list<string> */
    public array $signals;

    /** @var list<PostgresDdlEffect> */
    public array $postgresDdlEffects;

    /**
     * @param list<string> $signals
     * @param list<PostgresDdlEffect> $postgresDdlEffects
     */
    public function __construct(
        public string $migration,
        public string $ownerId,
        public string $sourcePath,
        public MigrationClassification $classification,
        array $signals,
        array $postgresDdlEffects = [],
    ) {
        $signals = array_values(array_unique($signals));
        sort($signals, SORT_STRING);
        $this->signals = $signals;
        $this->postgresDdlEffects = $postgresDdlEffects;
    }

    public function action(): MigrationCompactionAction
    {
        return $this->classification->action();
    }
}
