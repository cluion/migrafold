<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Discovery;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;
use Cluion\Migrafold\Output\SourceMigration;

final readonly class DiscoveredMigration
{
    public function __construct(
        public string $name,
        public string $absolutePath,
        public string $ownerId,
        public SourceMigration $source,
    ) {
        $portable = str_replace('\\', '/', $absolutePath);
        $absolute = str_starts_with($portable, '/') || preg_match('/\A[A-Za-z]:\//', $portable) === 1;
        $sourceFilename = basename($source->path);

        if (
            $name === ''
            || str_contains($name, "\0")
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || ! $absolute
            || $ownerId === ''
            || ! str_ends_with($sourceFilename, '.php')
            || substr($sourceFilename, 0, -4) !== $name
            || basename($absolutePath) !== $sourceFilename
        ) {
            throw MigrationDiscoveryFailed::because(
                "discovered migration [{$source->path}] has inconsistent identity or path metadata.",
            );
        }
    }
}
