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
        /** @var list<array{migration: string, effect: PostgresDdlEffect}> $effects */
        $effects = [];

        foreach ($analysis->entries as $entry) {
            foreach ($entry->analysis->postgresDdlEffects as $effect) {
                $effects[] = [
                    'migration' => $entry->migration->name,
                    'effect' => $effect,
                ];
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

        foreach ($effects as $position => $entry) {
            $migration = $entry['migration'];
            $effect = $entry['effect'];
            $table = $tables[$effect->table] ?? null;

            if (! $table instanceof TableDefinition
                || ! $this->contains($table, $effect, $effects, $position)) {
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

    /**
     * @param list<array{migration: string, effect: PostgresDdlEffect}> $effects
     */
    private function contains(
        TableDefinition $table,
        PostgresDdlEffect $effect,
        array $effects,
        int $position,
    ): bool
    {
        if ($effect->type === PostgresDdlEffectType::CreateIndex) {
            return in_array($effect->object, $this->indexNames($table), true);
        }

        if (in_array($effect->type, [
            PostgresDdlEffectType::DropColumnNotNull,
            PostgresDdlEffectType::SetColumnNotNull,
        ], true)) {
            return $this->matchesFinalNullability($table, $effects, $position, $effect);
        }

        if ($effect->type === PostgresDdlEffectType::DropConstraint) {
            return ! $this->containsConstraint($table, $effect->object)
                || $this->constraintIsRecreated($effects, $position, $effect);
        }

        $name = $effect->type === PostgresDdlEffectType::RenameConstraint
            ? $effect->targetObject
            : $effect->object;

        if ($name === null) {
            return false;
        }

        $name = $this->finalConstraintName($effects, $position, $effect->table, $name);

        if ($name === null) {
            return true;
        }

        if (in_array($effect->type, [
            PostgresDdlEffectType::AddCheckConstraint,
            PostgresDdlEffectType::ValidateConstraint,
        ], true)) {
            return in_array(
                $name,
                array_column($table->checkConstraints, 'name'),
                true,
            );
        }

        return $this->containsConstraint($table, $name);
    }

    /**
     * @param list<array{migration: string, effect: PostgresDdlEffect}> $effects
     */
    private function matchesFinalNullability(
        TableDefinition $table,
        array $effects,
        int $position,
        PostgresDdlEffect $effect,
    ): bool {
        $nullable = $effect->type === PostgresDdlEffectType::DropColumnNotNull;

        for ($index = $position + 1, $count = count($effects); $index < $count; $index++) {
            $later = $effects[$index]['effect'];

            if ($later->table !== $effect->table || $later->object !== $effect->object) {
                continue;
            }

            if ($later->type === PostgresDdlEffectType::DropColumnNotNull) {
                $nullable = true;
            } elseif ($later->type === PostgresDdlEffectType::SetColumnNotNull) {
                $nullable = false;
            }
        }

        foreach ($table->columns as $column) {
            if ($column->name === $effect->object) {
                return $column->nullable === $nullable;
            }
        }

        return false;
    }

    /**
     * Resolve the final name of one constraint through later rename/drop effects.
     *
     * @param list<array{migration: string, effect: PostgresDdlEffect}> $effects
     */
    private function finalConstraintName(
        array $effects,
        int $position,
        string $table,
        string $name,
    ): ?string
    {
        for ($index = $position + 1, $count = count($effects); $index < $count; $index++) {
            $later = $effects[$index]['effect'];

            if ($later->table !== $table || $later->object !== $name) {
                continue;
            }

            if ($later->type === PostgresDdlEffectType::DropConstraint) {
                return null;
            }

            if ($later->type === PostgresDdlEffectType::RenameConstraint) {
                if ($later->targetObject === null) {
                    return null;
                }

                $name = $later->targetObject;
            }
        }

        return $name;
    }

    /**
     * @param list<array{migration: string, effect: PostgresDdlEffect}> $effects
     */
    private function constraintIsRecreated(
        array $effects,
        int $position,
        PostgresDdlEffect $dropped,
    ): bool
    {
        for ($index = $position + 1, $count = count($effects); $index < $count; $index++) {
            $later = $effects[$index]['effect'];

            if ($later->table !== $dropped->table) {
                continue;
            }

            if ($later->type === PostgresDdlEffectType::AddCheckConstraint
                && $later->object === $dropped->object
                && $this->finalConstraintName(
                    $effects,
                    $index,
                    $later->table,
                    $later->object,
                ) === $dropped->object) {
                return true;
            }

            if ($later->type === PostgresDdlEffectType::RenameConstraint
                && $later->targetObject === $dropped->object
                && $this->finalConstraintName(
                    $effects,
                    $index,
                    $later->table,
                    $later->targetObject,
                ) === $dropped->object) {
                return true;
            }
        }

        return false;
    }

    private function containsConstraint(TableDefinition $table, string $name): bool
    {
        return in_array($name, [
            ...array_column($table->checkConstraints, 'name'),
            ...array_column($table->foreignKeys, 'name'),
            ...array_column($table->indexes, 'name'),
        ], true);
    }

    /** @return list<string> */
    private function indexNames(TableDefinition $table): array
    {
        return [
            ...array_column($table->indexes, 'name'),
            ...array_column($table->expressionIndexes, 'name'),
            ...array_column($table->partialIndexes, 'name'),
            ...array_column($table->multiKeyExpressionIndexes, 'name'),
            ...array_column($table->ginIndexes, 'name'),
        ];
    }
}
