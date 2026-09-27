<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Analysis\MigrationAnalysisReport;
use Cluion\Migrafold\Analysis\PostgresDdlEffect;
use Cluion\Migrafold\Analysis\PostgresDdlEffectType;
use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use Cluion\Migrafold\Schema\Definition\TableDefinition;

final class PostgresDdlCoverageValidator
{
    public function assertCovered(MigrationAnalysisReport $analysis, SchemaSnapshot $snapshot): void
    {
        $effects = [];

        foreach ($analysis->entries as $entry) {
            foreach ($entry->analysis->postgresDdlEffects as $effect) {
                $effects[] = [$entry->migration->name, $effect];
            }
        }

        if ($effects === []) {
            return;
        }

        if ($snapshot->driver !== 'pgsql') {
            throw ReplayVerificationFailed::because(
                "PostgreSQL literal DDL coverage cannot use [{$snapshot->driver}] schema metadata.",
            );
        }

        $tables = [];

        foreach ($snapshot->tables as $table) {
            if ($table->schema === null || $table->schema === 'public') {
                $tables[$table->name] = $table;
            }
        }

        foreach ($effects as [$migration, $effect]) {
            $table = $tables[$effect->table] ?? null;

            if (! $table instanceof TableDefinition || ! $this->contains($table, $effect)) {
                throw ReplayVerificationFailed::because(sprintf(
                    'migration [%s] PostgreSQL literal DDL effect [%s public.%s.%s] at line %d is not represented by the source replay snapshot.',
                    $migration,
                    $effect->type->value,
                    $effect->table,
                    $effect->object,
                    $effect->line,
                ));
            }
        }
    }

    private function contains(TableDefinition $table, PostgresDdlEffect $effect): bool
    {
        return match ($effect->type) {
            PostgresDdlEffectType::AddCheckConstraint => in_array(
                $effect->object,
                array_column($table->checkConstraints, 'name'),
                true,
            ),
            PostgresDdlEffectType::CreateIndex => in_array(
                $effect->object,
                [
                    ...array_column($table->indexes, 'name'),
                    ...array_column($table->expressionIndexes, 'name'),
                    ...array_column($table->partialIndexes, 'name'),
                    ...array_column($table->multiKeyExpressionIndexes, 'name'),
                    ...array_column($table->ginIndexes, 'name'),
                ],
                true,
            ),
        };
    }
}
