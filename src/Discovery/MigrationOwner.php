<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Discovery;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;

final readonly class MigrationOwner
{
    /** @var list<string> */
    public array $tables;

    /**
     * @param list<string> $tables
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $migrationDirectory,
        array $tables,
        public bool $fallback = false,
    ) {
        if (preg_match('/\A[a-z][a-z0-9.-]*:[A-Za-z][A-Za-z0-9_.-]*\z/', $id) !== 1) {
            throw MigrationDiscoveryFailed::because("migration owner id [{$id}] is invalid.");
        }

        if ($name === '' || trim($name) !== $name) {
            throw MigrationDiscoveryFailed::because("migration owner [{$id}] has an invalid name.");
        }

        $portableDirectory = str_replace('\\', '/', $migrationDirectory);
        $segments = explode('/', $portableDirectory);
        $absolute = str_starts_with($portableDirectory, '/')
            || preg_match('/\A[A-Za-z]:\//', $portableDirectory) === 1;

        if (
            str_contains($migrationDirectory, "\0")
            || ! $absolute
            || $portableDirectory === '/'
            || preg_match('/\A[A-Za-z]:\/?\z/', $portableDirectory) === 1
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw MigrationDiscoveryFailed::because(
                "migration directory [{$migrationDirectory}] for owner [{$id}] is unsafe.",
            );
        }

        $normalizedTables = [];

        foreach ($tables as $table) {
            if (preg_match('/\A[A-Za-z_$][A-Za-z0-9_$-]*(?:\.[A-Za-z_$][A-Za-z0-9_$-]*)*\z/', $table) !== 1) {
                throw MigrationDiscoveryFailed::because(
                    "migration owner [{$id}] declares invalid table [{$table}].",
                );
            }

            $key = strtolower($table);

            if (isset($normalizedTables[$key])) {
                throw MigrationDiscoveryFailed::because(
                    "migration owner [{$id}] declares duplicate table [{$table}].",
                );
            }

            $normalizedTables[$key] = $table;
        }

        ksort($normalizedTables, SORT_STRING);
        $this->tables = array_values($normalizedTables);
    }

    public function owns(string $table): bool
    {
        foreach ($this->tables as $ownedTable) {
            if (strcasecmp($ownedTable, $table) === 0) {
                return true;
            }
        }

        return false;
    }
}
