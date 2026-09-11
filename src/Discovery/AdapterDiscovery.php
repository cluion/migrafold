<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Discovery;

final readonly class AdapterDiscovery
{
    /**
     * @param list<MigrationOwner> $owners
     * @param list<DiscoveredMigration> $migrations
     */
    public function __construct(
        public array $owners,
        public array $migrations,
    ) {}
}
