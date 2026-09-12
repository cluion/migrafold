<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Verification;

final readonly class InstalledCompactionVerification
{
    /** @var list<array{owner: string, path: string}> */
    public array $manifests;

    /** @var list<string> */
    public array $compacted;

    /** @var list<string> */
    public array $preserved;

    /** @var list<string> */
    public array $baselines;

    /** @var list<string> */
    public array $preservedRecorded;

    /** @var list<string> */
    public array $preservedPending;

    /** @var list<string> */
    public array $untrackedMigrations;

    /**
     * @param list<array{owner: string, path: string}> $manifests
     * @param list<string> $compacted
     * @param list<string> $preserved
     * @param list<string> $baselines
     * @param list<string> $preservedRecorded
     * @param list<string> $preservedPending
     * @param list<string> $untrackedMigrations
     */
    public function __construct(
        public string $driver,
        public string $schemaFingerprint,
        public string $migrationTable,
        public int $baselineBatch,
        array $manifests,
        array $compacted,
        array $preserved,
        array $baselines,
        array $preservedRecorded,
        array $preservedPending,
        array $untrackedMigrations,
    ) {
        $this->manifests = $manifests;
        $this->compacted = $compacted;
        $this->preserved = $preserved;
        $this->baselines = $baselines;
        $this->preservedRecorded = $preservedRecorded;
        $this->preservedPending = $preservedPending;
        $this->untrackedMigrations = $untrackedMigrations;
    }

    /**
     * @return array{
     *     verified: true,
     *     schema: array{driver: string, fingerprint: string},
     *     manifests: list<array{owner: string, path: string}>,
     *     migrations: array{compacted: list<string>, preserved: list<string>, baselines: list<string>, untracked: list<string>},
     *     records: array{table: string, baseline_batch: int, preserved_recorded: list<string>, preserved_pending: list<string>}
     * }
     */
    public function summary(): array
    {
        return [
            'verified' => true,
            'schema' => [
                'driver' => $this->driver,
                'fingerprint' => $this->schemaFingerprint,
            ],
            'manifests' => $this->manifests,
            'migrations' => [
                'compacted' => $this->compacted,
                'preserved' => $this->preserved,
                'baselines' => $this->baselines,
                'untracked' => $this->untrackedMigrations,
            ],
            'records' => [
                'table' => $this->migrationTable,
                'baseline_batch' => $this->baselineBatch,
                'preserved_recorded' => $this->preservedRecorded,
                'preserved_pending' => $this->preservedPending,
            ],
        ];
    }
}
