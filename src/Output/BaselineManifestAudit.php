<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

use Cluion\Migrafold\Analysis\AnalyzedMigration;
use Cluion\Migrafold\Analysis\MigrationCompactionAction;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Output\Exception\UnsafeOutputOperation;
use Cluion\Migrafold\Replay\ReplayVerificationResult;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;

/**
 * @phpstan-type MigrationEntry array{name: string, owner: string, path: string, sha256: string, classification: string, action: string}
 * @phpstan-type BaselineEntry array{filename: string, table: string, sha256: string}
 */
final readonly class BaselineManifestAudit
{
    /** @var list<MigrationEntry> */
    private array $compacted;

    /** @var list<MigrationEntry> */
    private array $preserved;

    /** @var list<BaselineEntry> */
    private array $baselines;

    /**
     * @param list<MigrationEntry> $compacted
     * @param list<MigrationEntry> $preserved
     * @param list<BaselineEntry> $baselines
     */
    private function __construct(
        private string $driver,
        private string $sourceSchemaFingerprint,
        private string $baselineSchemaFingerprint,
        private string $currentSchemaFingerprint,
        private int $sourceMigrations,
        private int $baselineMigrations,
        private int $preservedMigrations,
        array $compacted,
        array $preserved,
        array $baselines,
    ) {
        $this->compacted = $compacted;
        $this->preserved = $preserved;
        $this->baselines = $baselines;
    }

    public static function fromReplay(
        ReplayVerificationResult $replay,
        SchemaSnapshot $current,
    ): self {
        $replay->analysis->assertReplayable();
        $sourceFingerprint = $replay->source->fingerprint();
        $baselineFingerprint = $replay->baseline->fingerprint();
        $currentFingerprint = $current->fingerprint();

        if ($replay->source->driver !== $replay->baseline->driver
            || $replay->source->driver !== $current->driver) {
            throw UnsafeOutputOperation::because(
                'manifest replay evidence does not use one database driver.',
            );
        }

        if ($sourceFingerprint !== $baselineFingerprint || $sourceFingerprint !== $currentFingerprint) {
            throw UnsafeOutputOperation::because(
                'manifest replay evidence contains different schema fingerprints.',
            );
        }

        $compacted = self::migrationEntries(
            $replay->analysis->forAction(MigrationCompactionAction::Compact),
        );
        $preserved = self::migrationEntries(
            $replay->analysis->forAction(MigrationCompactionAction::Preserve),
        );
        $baselines = array_map(
            static fn (GeneratedMigration $migration): array => [
                'filename' => $migration->filename,
                'table' => $migration->table,
                'sha256' => hash('sha256', $migration->contents),
            ],
            $replay->baselines,
        );

        if ($compacted === []) {
            throw UnsafeOutputOperation::because(
                'manifest replay evidence contains no compacted migration.',
            );
        }

        if ($replay->sourceMigrations !== count($replay->analysis->entries)
            || $replay->baselineMigrations !== count($baselines)
            || $replay->preservedMigrations !== count($preserved)) {
            throw UnsafeOutputOperation::because(
                'manifest replay evidence contains inconsistent migration counts.',
            );
        }

        return new self(
            driver: $replay->source->driver,
            sourceSchemaFingerprint: $sourceFingerprint,
            baselineSchemaFingerprint: $baselineFingerprint,
            currentSchemaFingerprint: $currentFingerprint,
            sourceMigrations: $replay->sourceMigrations,
            baselineMigrations: $replay->baselineMigrations,
            preservedMigrations: $replay->preservedMigrations,
            compacted: $compacted,
            preserved: $preserved,
            baselines: $baselines,
        );
    }

    /** @param list<GeneratedMigration> $baselines */
    public function assertOutputScope(MigrationCatalog $compacted, array $baselines): void
    {
        $catalogEntries = array_map(
            static fn ($migration): array => [
                'name' => $migration->name,
                'owner' => $migration->ownerId,
                'path' => $migration->source->path,
                'sha256' => $migration->source->sha256,
            ],
            $compacted->migrations,
        );
        $auditEntries = array_map(
            static fn (array $migration): array => [
                'name' => $migration['name'],
                'owner' => $migration['owner'],
                'path' => $migration['path'],
                'sha256' => $migration['sha256'],
            ],
            $this->compacted,
        );
        $baselineEntries = array_map(
            static fn (GeneratedMigration $migration): array => [
                'filename' => $migration->filename,
                'table' => $migration->table,
                'sha256' => hash('sha256', $migration->contents),
            ],
            $baselines,
        );

        if ($catalogEntries !== $auditEntries || $baselineEntries !== $this->baselines) {
            throw UnsafeOutputOperation::because(
                'manifest replay evidence does not match the planned output scope.',
            );
        }
    }

    /**
     * @return array{
     *     verification: array{
     *         mode: string,
     *         driver: string,
     *         schema_fingerprints: array{source_replay: string, baseline_replay: string, current_database: string},
     *         migration_counts: array{source: int, baseline: int, preserved: int},
     *         data_state_compared: false
     *     },
     *     migration_scope: array{
     *         compacted: list<MigrationEntry>,
     *         preserved: list<MigrationEntry>
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'verification' => [
                'mode' => 'same-engine-dual-sandbox',
                'driver' => $this->driver,
                'schema_fingerprints' => [
                    'source_replay' => $this->sourceSchemaFingerprint,
                    'baseline_replay' => $this->baselineSchemaFingerprint,
                    'current_database' => $this->currentSchemaFingerprint,
                ],
                'migration_counts' => [
                    'source' => $this->sourceMigrations,
                    'baseline' => $this->baselineMigrations,
                    'preserved' => $this->preservedMigrations,
                ],
                'data_state_compared' => false,
            ],
            'migration_scope' => [
                'compacted' => $this->compacted,
                'preserved' => $this->preserved,
            ],
        ];
    }

    /**
     * @param list<AnalyzedMigration> $migrations
     * @return list<MigrationEntry>
     */
    private static function migrationEntries(array $migrations): array
    {
        $entries = array_map(
            static fn (AnalyzedMigration $entry): array => [
                'name' => $entry->migration->name,
                'owner' => $entry->migration->ownerId,
                'path' => $entry->migration->source->path,
                'sha256' => $entry->migration->source->sha256,
                'classification' => $entry->analysis->classification->value,
                'action' => $entry->analysis->action()->value,
            ],
            $migrations,
        );

        usort(
            $entries,
            static fn (array $left, array $right): int => strcmp($left['name'], $right['name'])
                ?: strcmp($left['path'], $right['path']),
        );

        return $entries;
    }
}
