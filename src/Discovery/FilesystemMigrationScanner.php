<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Discovery;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;
use Cluion\Migrafold\Output\Exception\UnsafeOutputOperation;
use Cluion\Migrafold\Output\SourceMigration;

final class FilesystemMigrationScanner
{
    /** @return list<DiscoveredMigration> */
    public function scan(string $projectRoot, MigrationOwner $owner): array
    {
        $root = realpath($projectRoot);

        if ($root === false || ! is_dir($root)) {
            throw MigrationDiscoveryFailed::because("project root [{$projectRoot}] is not a readable directory.");
        }

        if (! is_dir($owner->migrationDirectory)) {
            return [];
        }

        if (is_link($owner->migrationDirectory)) {
            throw MigrationDiscoveryFailed::because(
                "migration directory [{$owner->migrationDirectory}] must not be a symbolic link.",
            );
        }

        $directory = realpath($owner->migrationDirectory);

        if ($directory === false || ! $this->within($root, $directory)) {
            throw MigrationDiscoveryFailed::because(
                "migration directory [{$owner->migrationDirectory}] is outside project root [{$projectRoot}].",
            );
        }

        $matches = glob($directory.DIRECTORY_SEPARATOR.'*_*.php');

        if ($matches === false) {
            throw MigrationDiscoveryFailed::because("migration directory [{$directory}] could not be scanned.");
        }

        sort($matches, SORT_STRING);
        $migrations = [];

        foreach ($matches as $file) {
            if (is_link($file)) {
                throw MigrationDiscoveryFailed::because("migration source [{$file}] must not be a symbolic link.");
            }

            if (! is_file($file)) {
                continue;
            }

            try {
                $source = SourceMigration::fromFile($root, $file);
            } catch (UnsafeOutputOperation $exception) {
                throw MigrationDiscoveryFailed::because(
                    "migration source [{$file}] could not be fingerprinted safely: {$exception->getMessage()}",
                );
            }

            $migrations[] = new DiscoveredMigration(
                name: pathinfo($file, PATHINFO_FILENAME),
                absolutePath: $file,
                ownerId: $owner->id,
                source: $source,
            );
        }

        return $migrations;
    }

    private function within(string $root, string $path): bool
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix);
    }
}
