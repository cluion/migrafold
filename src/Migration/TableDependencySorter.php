<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Migration;

use Cluion\Migrafold\Migration\Exception\UnsupportedMigrationGeneration;
use Cluion\Migrafold\Schema\Definition\TableDefinition;

final class TableDependencySorter
{
    /**
     * @param list<TableDefinition> $tables
     * @return list<TableDefinition>
     */
    public function sort(array $tables): array
    {
        $byName = [];

        foreach ($tables as $table) {
            $key = $this->key($table->schema, $table->name);

            if (isset($byName[$key])) {
                throw UnsupportedMigrationGeneration::forSchema("duplicate table [{$key}].");
            }

            $byName[$key] = $table;
        }

        ksort($byName, SORT_STRING);

        /** @var array<string, 1|2> $state */
        $state = [];
        $sorted = [];

        foreach (array_keys($byName) as $key) {
            $this->visit($key, $byName, $state, $sorted, []);
        }

        return $sorted;
    }

    /**
     * @param array<string, TableDefinition> $tables
     * @param array<string, 1|2> $state
     * @param list<TableDefinition> $sorted
     * @param list<string> $path
     */
    private function visit(string $key, array $tables, array &$state, array &$sorted, array $path): void
    {
        if (($state[$key] ?? null) === 2) {
            return;
        }

        if (($state[$key] ?? null) === 1) {
            $path[] = $key;

            throw UnsupportedMigrationGeneration::forSchema(
                'cyclic table dependencies ['.implode(' -> ', $path).'].',
            );
        }

        $state[$key] = 1;
        $path[] = $key;
        $table = $tables[$key];
        $dependencies = [];

        foreach ($table->foreignKeys as $foreignKey) {
            $foreignSchema = $foreignKey->foreignSchema ?? $table->schema;
            $dependency = $this->key($foreignSchema, $foreignKey->foreignTable);

            if ($dependency === $key) {
                continue;
            }

            if (! isset($tables[$dependency])) {
                throw UnsupportedMigrationGeneration::forSchema(
                    "foreign key on table [{$key}] references missing table [{$dependency}].",
                );
            }

            $dependencies[$dependency] = true;
        }

        $dependencyKeys = array_keys($dependencies);
        sort($dependencyKeys, SORT_STRING);

        foreach ($dependencyKeys as $dependency) {
            $this->visit($dependency, $tables, $state, $sorted, $path);
        }

        $state[$key] = 2;
        $sorted[] = $table;
    }

    private function key(?string $schema, string $table): string
    {
        return ($schema === null ? '' : $schema.'.').$table;
    }
}
