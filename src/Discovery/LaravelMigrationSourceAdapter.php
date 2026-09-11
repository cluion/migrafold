<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Discovery;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;

final readonly class LaravelMigrationSourceAdapter implements MigrationSourceAdapter
{
    /**
     * @param list<string> $tables
     */
    public function __construct(
        private string $projectRoot,
        private string $relativeMigrationDirectory = 'database/migrations',
        private array $tables = [],
        private FilesystemMigrationScanner $scanner = new FilesystemMigrationScanner(),
    ) {
        $portable = str_replace('\\', '/', $relativeMigrationDirectory);
        $segments = explode('/', $portable);

        if (
            $relativeMigrationDirectory === ''
            || str_starts_with($portable, '/')
            || preg_match('/\A[A-Za-z]:\//', $portable) === 1
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw MigrationDiscoveryFailed::because(
                "Laravel migration directory [{$relativeMigrationDirectory}] must be project-relative.",
            );
        }
    }

    public function discover(): AdapterDiscovery
    {
        $root = realpath($this->projectRoot);

        if ($root === false) {
            throw MigrationDiscoveryFailed::because("project root [{$this->projectRoot}] does not exist.");
        }

        $owner = new MigrationOwner(
            id: 'laravel:application',
            name: 'application',
            migrationDirectory: $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->relativeMigrationDirectory),
            tables: $this->tables,
            fallback: true,
        );

        return new AdapterDiscovery([$owner], $this->scanner->scan($root, $owner));
    }
}
