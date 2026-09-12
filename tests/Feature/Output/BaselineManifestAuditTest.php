<?php

declare(strict_types=1);

namespace Tests\Feature\Output;

use Cluion\Migrafold\Analysis\AnalyzedMigration;
use Cluion\Migrafold\Analysis\MigrationAnalysis;
use Cluion\Migrafold\Analysis\MigrationAnalysisReport;
use Cluion\Migrafold\Analysis\MigrationClassification;
use Cluion\Migrafold\Discovery\DiscoveredMigration;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Discovery\MigrationOwner;
use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Output\BaselineManifestAudit;
use Cluion\Migrafold\Output\Exception\UnsafeOutputOperation;
use Cluion\Migrafold\Output\SourceMigration;
use Cluion\Migrafold\Replay\ReplayVerificationResult;
use Cluion\Migrafold\Schema\Definition\CapabilityReport;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use PHPUnit\Framework\TestCase;

final class BaselineManifestAuditTest extends TestCase
{
    public function test_it_serializes_verified_replay_and_global_migration_scope(): void
    {
        $fixture = $this->fixture();
        $replay = new ReplayVerificationResult(
            source: $fixture['snapshot'],
            baseline: $fixture['snapshot'],
            analysis: $fixture['analysis'],
            baselines: [$fixture['baseline']],
            sourceMigrations: 2,
            baselineMigrations: 1,
            preservedMigrations: 1,
        );
        $fingerprint = $fixture['snapshot']->fingerprint();

        self::assertSame([
            'verification' => [
                'mode' => 'same-engine-dual-sandbox',
                'driver' => 'sqlite',
                'schema_fingerprints' => [
                    'source_replay' => $fingerprint,
                    'baseline_replay' => $fingerprint,
                    'current_database' => $fingerprint,
                ],
                'migration_counts' => [
                    'source' => 2,
                    'baseline' => 1,
                    'preserved' => 1,
                ],
                'data_state_compared' => false,
            ],
            'migration_scope' => [
                'compacted' => [[
                    'name' => '2020_01_01_000000_create_users_table',
                    'owner' => 'laravel:application',
                    'path' => 'database/migrations/2020_01_01_000000_create_users_table.php',
                    'sha256' => str_repeat('a', 64),
                    'classification' => 'schema_only',
                    'action' => 'compact',
                ]],
                'preserved' => [[
                    'name' => '2030_01_01_000000_seed_system_user',
                    'owner' => 'laravel:application',
                    'path' => 'database/migrations/2030_01_01_000000_seed_system_user.php',
                    'sha256' => str_repeat('b', 64),
                    'classification' => 'data_only',
                    'action' => 'preserve',
                ]],
            ],
        ], BaselineManifestAudit::fromReplay($replay, $fixture['snapshot'])->toArray());
    }

    public function test_it_rejects_replay_evidence_with_different_schema_fingerprints(): void
    {
        $fixture = $this->fixture();
        $different = new SchemaSnapshot(
            'schema-ir-v1',
            'sqlite',
            new CapabilityReport(['different'], []),
            [],
        );
        $replay = new ReplayVerificationResult(
            source: $fixture['snapshot'],
            baseline: $different,
            analysis: $fixture['analysis'],
            baselines: [$fixture['baseline']],
            sourceMigrations: 2,
            baselineMigrations: 1,
            preservedMigrations: 1,
        );

        $this->expectException(UnsafeOutputOperation::class);
        $this->expectExceptionMessage('different schema fingerprints');

        BaselineManifestAudit::fromReplay($replay, $fixture['snapshot']);
    }

    public function test_it_rejects_output_scope_that_differs_from_verified_replay(): void
    {
        $fixture = $this->fixture();
        $replay = new ReplayVerificationResult(
            source: $fixture['snapshot'],
            baseline: $fixture['snapshot'],
            analysis: $fixture['analysis'],
            baselines: [$fixture['baseline']],
            sourceMigrations: 2,
            baselineMigrations: 1,
            preservedMigrations: 1,
        );
        $audit = BaselineManifestAudit::fromReplay($replay, $fixture['snapshot']);
        $differentBaseline = new GeneratedMigration(
            $fixture['baseline']->filename,
            $fixture['baseline']->table,
            "<?php\n// changed\n",
        );

        $this->expectException(UnsafeOutputOperation::class);
        $this->expectExceptionMessage('does not match the planned output scope');

        $audit->assertOutputScope($fixture['compacted'], [$differentBaseline]);
    }

    /**
     * @return array{
     *     snapshot: SchemaSnapshot,
     *     analysis: MigrationAnalysisReport,
     *     compacted: MigrationCatalog,
     *     baseline: GeneratedMigration
     * }
     */
    private function fixture(): array
    {
        $owner = new MigrationOwner(
            'laravel:application',
            'application',
            '/tmp/migrafold-manifest-audit/database/migrations',
            [],
            true,
        );
        $schemaMigration = $this->migration(
            '2020_01_01_000000_create_users_table',
            'database/migrations/2020_01_01_000000_create_users_table.php',
            'a',
        );
        $dataMigration = $this->migration(
            '2030_01_01_000000_seed_system_user',
            'database/migrations/2030_01_01_000000_seed_system_user.php',
            'b',
        );
        $analysis = new MigrationAnalysisReport([
            $this->analyzed($schemaMigration, MigrationClassification::SchemaOnly),
            $this->analyzed($dataMigration, MigrationClassification::DataOnly),
        ]);

        return [
            'snapshot' => new SchemaSnapshot(
                'schema-ir-v1',
                'sqlite',
                new CapabilityReport(['columns'], []),
                [],
            ),
            'analysis' => $analysis,
            'compacted' => new MigrationCatalog([$owner], [$schemaMigration]),
            'baseline' => new GeneratedMigration(
                '2026_09_12_000001_create_users_baseline.php',
                'users',
                "<?php\n// baseline\n",
            ),
        ];
    }

    private function migration(string $name, string $path, string $hash): DiscoveredMigration
    {
        return new DiscoveredMigration(
            $name,
            '/tmp/migrafold-manifest-audit/'.$path,
            'laravel:application',
            new SourceMigration($path, str_repeat($hash, 64)),
        );
    }

    private function analyzed(
        DiscoveredMigration $migration,
        MigrationClassification $classification,
    ): AnalyzedMigration {
        return new AnalyzedMigration(
            $migration,
            new MigrationAnalysis(
                $migration->name,
                $migration->ownerId,
                $migration->source->path,
                $classification,
                [],
            ),
        );
    }
}
