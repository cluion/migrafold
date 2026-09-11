<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Discovery;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;
use Cluion\Migrafold\Output\SourceMigration;

final readonly class MigrationCatalog
{
    /** @var list<MigrationOwner> */
    public array $owners;

    /** @var list<DiscoveredMigration> */
    public array $migrations;

    /** @var array<string, MigrationOwner> */
    private array $ownersById;

    /** @var array<string, MigrationOwner> */
    private array $tableOwners;

    private ?MigrationOwner $fallbackOwner;

    /**
     * @param list<MigrationOwner> $owners
     * @param list<DiscoveredMigration> $migrations
     */
    public function __construct(array $owners, array $migrations)
    {
        if ($owners === []) {
            throw MigrationDiscoveryFailed::because('at least one migration owner is required.');
        }

        $ownersById = [];
        $tableOwners = [];
        $fallbackOwner = null;

        foreach ($owners as $owner) {
            $ownerKey = strtolower($owner->id);

            if (isset($ownersById[$ownerKey])) {
                throw MigrationDiscoveryFailed::because("duplicate migration owner [{$owner->id}].");
            }

            $ownersById[$ownerKey] = $owner;

            if ($owner->fallback) {
                if ($fallbackOwner !== null) {
                    throw MigrationDiscoveryFailed::because(
                        "migration owners [{$fallbackOwner->id}] and [{$owner->id}] are both configured as fallback.",
                    );
                }

                $fallbackOwner = $owner;
            }

            foreach ($owner->tables as $table) {
                $tableKey = strtolower($table);

                if (isset($tableOwners[$tableKey])) {
                    throw MigrationDiscoveryFailed::because(
                        "table [{$table}] is claimed by both [{$tableOwners[$tableKey]->id}] and [{$owner->id}].",
                    );
                }

                $tableOwners[$tableKey] = $owner;
            }
        }

        usort($owners, static fn (MigrationOwner $left, MigrationOwner $right): int => strcasecmp($left->id, $right->id));
        $names = [];
        $paths = [];

        foreach ($migrations as $migration) {
            if (! isset($ownersById[strtolower($migration->ownerId)])) {
                throw MigrationDiscoveryFailed::because(
                    "migration [{$migration->source->path}] references unknown owner [{$migration->ownerId}].",
                );
            }

            $nameKey = strtolower($migration->name);
            $pathKey = strtolower($migration->source->path);

            if (isset($names[$nameKey])) {
                throw MigrationDiscoveryFailed::because(
                    "migration name [{$migration->name}] is duplicated by [{$names[$nameKey]}] and [{$migration->source->path}].",
                );
            }

            if (isset($paths[$pathKey])) {
                throw MigrationDiscoveryFailed::because(
                    "migration source [{$migration->source->path}] was discovered more than once.",
                );
            }

            $names[$nameKey] = $migration->source->path;
            $paths[$pathKey] = true;
        }

        usort($migrations, static function (DiscoveredMigration $left, DiscoveredMigration $right): int {
            $name = strcasecmp($left->name, $right->name);

            return $name !== 0 ? $name : strcmp($left->source->path, $right->source->path);
        });

        $this->owners = $owners;
        $this->migrations = $migrations;
        $this->ownersById = $ownersById;
        $this->tableOwners = $tableOwners;
        $this->fallbackOwner = $fallbackOwner;
    }

    public function ownerForTable(string $table): MigrationOwner
    {
        $owner = $this->tableOwners[strtolower($table)] ?? null;

        if ($owner !== null) {
            return $owner;
        }

        if ($this->fallbackOwner !== null) {
            return $this->fallbackOwner;
        }

        if (count($this->owners) === 1) {
            return $this->owners[0];
        }

        throw MigrationDiscoveryFailed::because(
            "table [{$table}] has no explicit owner in a multi-owner project.",
        );
    }

    /** @return list<DiscoveredMigration> */
    public function migrationsFor(string $ownerId): array
    {
        if (! isset($this->ownersById[strtolower($ownerId)])) {
            throw MigrationDiscoveryFailed::because("migration owner [{$ownerId}] does not exist.");
        }

        return array_values(array_filter(
            $this->migrations,
            static fn (DiscoveredMigration $migration): bool => strcasecmp($migration->ownerId, $ownerId) === 0,
        ));
    }

    /** @return list<SourceMigration> */
    public function sourcesFor(string $ownerId): array
    {
        return array_map(
            static fn (DiscoveredMigration $migration): SourceMigration => $migration->source,
            $this->migrationsFor($ownerId),
        );
    }
}
