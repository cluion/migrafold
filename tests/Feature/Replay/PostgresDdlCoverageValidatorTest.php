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
use Cluion\Migrafold\Schema\Definition\ForeignKeyDefinition;
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

    public function test_it_reduces_a_check_constraint_replacement_to_its_final_name(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(PostgresDdlEffectType::AddCheckConstraint, 'users', 'users_state_check', 10),
            new PostgresDdlEffect(PostgresDdlEffectType::AddCheckConstraint, 'users', 'users_state_check_next', 20),
            new PostgresDdlEffect(PostgresDdlEffectType::ValidateConstraint, 'users', 'users_state_check_next', 30),
            new PostgresDdlEffect(PostgresDdlEffectType::DropConstraint, 'users', 'users_state_check', 40),
            new PostgresDdlEffect(
                PostgresDdlEffectType::RenameConstraint,
                'users',
                'users_state_check_next',
                41,
                'users_state_check',
            ),
        ]);

        (new PostgresDdlCoverageValidator())->assertCovered($report, $this->snapshot());

        self::addToAssertionCount(1);
    }

    public function test_it_accepts_a_constraint_drop_reflected_by_the_final_snapshot(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(PostgresDdlEffectType::DropConstraint, 'users', 'users_state_check', 12),
        ]);

        (new PostgresDdlCoverageValidator())->assertCovered($report, $this->snapshot([]));

        self::addToAssertionCount(1);
    }

    public function test_it_accepts_a_constraint_recreated_under_the_same_name(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(PostgresDdlEffectType::DropConstraint, 'users', 'users_state_check', 12),
            new PostgresDdlEffect(PostgresDdlEffectType::AddCheckConstraint, 'users', 'users_state_check', 13),
        ]);

        (new PostgresDdlCoverageValidator())->assertCovered($report, $this->snapshot());

        self::addToAssertionCount(1);
    }

    public function test_it_rejects_a_constraint_drop_not_reflected_by_the_final_snapshot(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(PostgresDdlEffectType::DropConstraint, 'users', 'users_state_check', 12),
        ]);

        $this->expectException(ReplayVerificationFailed::class);
        $this->expectExceptionMessage(
            'PostgreSQL literal DDL effect [drop_constraint public.users.users_state_check] at line 12 is not represented',
        );

        (new PostgresDdlCoverageValidator())->assertCovered($report, $this->snapshot());
    }

    public function test_it_rejects_a_constraint_rename_without_its_final_target(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(
                PostgresDdlEffectType::RenameConstraint,
                'users',
                'users_state_check',
                12,
                'users_state_check_next',
            ),
        ]);

        $this->expectException(ReplayVerificationFailed::class);
        $this->expectExceptionMessage(
            'PostgreSQL literal DDL effect [rename_constraint public.users.users_state_check] at line 12 is not represented',
        );

        (new PostgresDdlCoverageValidator())->assertCovered($report, $this->snapshot());
    }

    public function test_it_rejects_validation_without_a_final_check_constraint(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(PostgresDdlEffectType::ValidateConstraint, 'users', 'users_missing', 12),
        ]);

        $this->expectException(ReplayVerificationFailed::class);
        $this->expectExceptionMessage(
            'PostgreSQL literal DDL effect [validate_constraint public.users.users_missing] at line 12 is not represented',
        );

        (new PostgresDdlCoverageValidator())->assertCovered($report, $this->snapshot());
    }

    public function test_it_accepts_a_named_foreign_key_present_in_the_final_snapshot(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(
                PostgresDdlEffectType::AddForeignKeyConstraint,
                'users',
                'users_parent_fk',
                12,
            ),
        ]);

        (new PostgresDdlCoverageValidator())->assertCovered(
            $report,
            $this->snapshot(foreignKeyNames: ['users_parent_fk']),
        );

        self::addToAssertionCount(1);
    }

    public function test_it_rejects_a_foreign_key_missing_from_the_final_snapshot(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(
                PostgresDdlEffectType::AddForeignKeyConstraint,
                'users',
                'users_parent_fk',
                12,
            ),
        ]);

        $this->expectException(ReplayVerificationFailed::class);
        $this->expectExceptionMessage(
            'PostgreSQL literal DDL effect [add_foreign_key_constraint public.users.users_parent_fk] at line 12 is not represented',
        );

        (new PostgresDdlCoverageValidator())->assertCovered($report, $this->snapshot());
    }

    public function test_it_accepts_a_foreign_key_recreated_under_the_same_name(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(PostgresDdlEffectType::DropConstraint, 'users', 'users_parent_fk', 12),
            new PostgresDdlEffect(
                PostgresDdlEffectType::AddForeignKeyConstraint,
                'users',
                'users_parent_fk',
                13,
            ),
        ]);

        (new PostgresDdlCoverageValidator())->assertCovered(
            $report,
            $this->snapshot(foreignKeyNames: ['users_parent_fk']),
        );

        self::addToAssertionCount(1);
    }

    public function test_it_accepts_validation_of_a_foreign_key_in_the_final_snapshot(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(PostgresDdlEffectType::ValidateConstraint, 'users', 'users_parent_fk', 12),
        ]);

        (new PostgresDdlCoverageValidator())->assertCovered(
            $report,
            $this->snapshot(foreignKeyNames: ['users_parent_fk']),
        );

        self::addToAssertionCount(1);
    }

    public function test_it_accepts_a_nullable_column_reflected_by_the_final_snapshot(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(PostgresDdlEffectType::DropColumnNotNull, 'users', 'email', 12),
        ]);

        (new PostgresDdlCoverageValidator())->assertCovered($report, $this->snapshot(emailNullable: true));

        self::addToAssertionCount(1);
    }

    public function test_it_reduces_column_nullability_changes_to_the_final_state(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(PostgresDdlEffectType::DropColumnNotNull, 'users', 'email', 12),
            new PostgresDdlEffect(PostgresDdlEffectType::SetColumnNotNull, 'users', 'email', 13),
        ]);

        (new PostgresDdlCoverageValidator())->assertCovered($report, $this->snapshot());

        self::addToAssertionCount(1);
    }

    public function test_it_rejects_column_nullability_missing_from_the_final_snapshot(): void
    {
        $report = $this->report([
            new PostgresDdlEffect(PostgresDdlEffectType::DropColumnNotNull, 'users', 'email', 12),
        ]);

        $this->expectException(ReplayVerificationFailed::class);
        $this->expectExceptionMessage(
            'PostgreSQL literal DDL effect [drop_column_not_null public.users.email] at line 12 is not represented',
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

    /**
     * @param list<string> $checkNames
     * @param list<string> $foreignKeyNames
     */
    private function snapshot(
        array $checkNames = ['users_state_check'],
        bool $emailNullable = false,
        array $foreignKeyNames = [],
    ): SchemaSnapshot
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
                columns: [new ColumnDefinition(
                    'email',
                    'varchar(255)',
                    'varchar',
                    $emailNullable,
                    null,
                    false,
                    null,
                    null,
                    null,
                )],
                indexes: [],
                foreignKeys: array_map(
                    static fn (string $name): ForeignKeyDefinition => new ForeignKeyDefinition(
                        $name,
                        ['parent_id'],
                        'public',
                        'users',
                        ['id'],
                        null,
                        null,
                        true,
                        true,
                    ),
                    $foreignKeyNames,
                ),
                checkConstraints: array_map(
                    static fn (string $name): CheckConstraintDefinition => new CheckConstraintDefinition(
                        $name,
                        "state = 'active'",
                    ),
                    $checkNames,
                ),
                expressionIndexes: [new ExpressionIndexDefinition('users_email_ci', 'lower(email)', true)],
            )],
        );
    }
}
