<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Migration\GeneratedMigration;
use Cluion\Migrafold\Output\Exception\UnsafeOutputOperation;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use JsonException;

final readonly class OwnerAwareOutputPlanner
{
    public function __construct(
        private BaselineOutputPlanner $planner = new BaselineOutputPlanner(),
    ) {}

    /**
     * @param list<GeneratedMigration> $migrations
     * @throws JsonException
     */
    public function plan(
        SchemaSnapshot $snapshot,
        array $migrations,
        MigrationCatalog $catalog,
    ): OwnerAwareOutputPlan {
        if ($migrations === []) {
            throw UnsafeOutputOperation::because('at least one generated migration is required.');
        }

        /** @var array<string, list<GeneratedMigration>> $migrationsByOwner */
        $migrationsByOwner = [];
        $filenames = [];
        $tables = [];

        foreach ($migrations as $migration) {
            $filenameKey = strtolower($migration->filename);
            $tableKey = strtolower($migration->table);

            if (isset($filenames[$filenameKey])) {
                throw UnsafeOutputOperation::because(
                    "duplicate generated migration filename [{$migration->filename}] across owners.",
                );
            }

            if (isset($tables[$tableKey])) {
                throw UnsafeOutputOperation::because(
                    "duplicate generated table [{$migration->table}] across owners.",
                );
            }

            $filenames[$filenameKey] = true;
            $tables[$tableKey] = true;
            $owner = $catalog->ownerForTable($migration->table);
            $migrationsByOwner[strtolower($owner->id)][] = $migration;
        }

        foreach ($migrationsByOwner as &$ownerMigrations) {
            usort(
                $ownerMigrations,
                static fn (GeneratedMigration $left, GeneratedMigration $right): int => strcmp(
                    $left->filename,
                    $right->filename,
                ),
            );
        }

        unset($ownerMigrations);

        $plans = [];
        $directories = [];

        foreach ($catalog->owners as $owner) {
            $ownerMigrations = $migrationsByOwner[strtolower($owner->id)] ?? [];

            if ($ownerMigrations === []) {
                continue;
            }

            $directoryKey = strtolower(str_replace('\\', '/', rtrim($owner->migrationDirectory, '/\\')));

            if (isset($directories[$directoryKey])) {
                throw UnsafeOutputOperation::because(
                    "migration owners [{$directories[$directoryKey]}] and [{$owner->id}] share output directory [{$owner->migrationDirectory}].",
                );
            }

            $directories[$directoryKey] = $owner->id;
            $plans[] = new OwnerOutputPlan(
                ownerId: $owner->id,
                ownerName: $owner->name,
                output: $this->planner->plan(
                    $owner->migrationDirectory,
                    $snapshot,
                    $ownerMigrations,
                    $catalog->sourcesFor($owner->id),
                    new BaselineManifestOwner($owner->id, $owner->name),
                ),
            );
        }

        if ($plans === []) {
            throw UnsafeOutputOperation::because('no migration owner received generated output.');
        }

        return new OwnerAwareOutputPlan($plans);
    }
}
