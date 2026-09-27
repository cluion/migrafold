<?php

declare(strict_types=1);

namespace Tests\Feature\Replay;

use Cluion\Migrafold\Analysis\AnalyzedMigration;
use Cluion\Migrafold\Analysis\MigrationAnalysis;
use Cluion\Migrafold\Analysis\MigrationAnalysisReport;
use Cluion\Migrafold\Analysis\MigrationClassification;
use Cluion\Migrafold\Analysis\PostgresDdlEffect;
use Cluion\Migrafold\Analysis\PostgresDdlEffectType;
use Cluion\Migrafold\Discovery\DiscoveredMigration;
use Cluion\Migrafold\Output\SourceMigration;
use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Cluion\Migrafold\Replay\PostgresDdlCoverageValidator;
use Cluion\Migrafold\Schema\Definition\CapabilityReport;
use Cluion\Migrafold\Schema\Definition\CheckConstraintDefinition;
use Cluion\Migrafold\Schema\Definition\ColumnDefinition;
use Cluion\Migrafold\Schema\Definition\ExpressionIndexDefinition;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use Cluion\Migrafold\Schema\Definition\TableDefinition;
use PHPUnit\Framework\TestCase;

final class PostgresDdlCoverageValidatorTest extends TestCase
{
    public function test_it_accepts_named_check_and_index_effects_present_in_the_snapshot(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(PostgresDdlEffectType::AddCheckConstraint, 'users', 'users_state_check', 12),
            new PostgresDdlEffect(PostgresDdlEffectType::CreateIndex, 'users', 'users_email_ci', 13),
        ]);

        (new PostgresDdlCoverageValidator())->assertCovered($report, $this->snapshot());

        self::addToAssertionCount(1);
    }

    public function test_it_rejects_an_effect_missing_from_the_source_snapshot(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(PostgresDdlEffectType::CreateIndex, 'users', 'users_missing', 27),
        ]);

        $this->expectException(ReplayVerificationFailed::class);
        $this->expectExceptionMessage(
            'PostgreSQL literal DDL effect [create_index public.users.users_missing] at line 27 is not represented',
        );

        (new PostgresDdlCoverageValidator())->assertCovered($report, $this->snapshot());
    }

    /** @param list<PostgresDdlEffect> $effects */
    private function report(array $effects): MigrationAnalysisReport
    {
        $name = '2020_01_01_000000_fixture';
        $source = new SourceMigration('database/migrations/'.$name.'.php', str_repeat('a', 64));
        $migration = new DiscoveredMigration(
            $name,
            '/tmp/'.$name.'.php',
            'laravel:application',
            $source,
        );

        return new MigrationAnalysisReport([
            new AnalyzedMigration(
                $migration,
                new MigrationAnalysis(
                    $name,
                    'laravel:application',
                    $source->path,
                    MigrationClassification::SchemaOnly,
                    [],
                    $effects,
                ),
            ),
        ]);
    }

    private function snapshot(): SchemaSnapshot
    {
        return new SchemaSnapshot(
            '1',
            'pgsql',
            new CapabilityReport([], []),
            [new TableDefinition(
                name: 'users',
                schema: 'public',
                collation: null,
                engine: null,
                comment: null,
                columns: [new ColumnDefinition('email', 'varchar(255)', 'varchar', false, null, false, null, null, null)],
                indexes: [],
                foreignKeys: [],
                checkConstraints: [new CheckConstraintDefinition('users_state_check', "state = 'active'")],
                expressionIndexes: [new ExpressionIndexDefinition('users_email_ci', 'lower(email)', true)],
            )],
        );
    }
}
