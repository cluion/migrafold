<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Discovery;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;

final class MigrationDiscoverer
{
    /**
     * @param list<MigrationSourceAdapter> $adapters
     */
    public function discover(array $adapters): MigrationCatalog
    {
        if ($adapters === []) {
            throw MigrationDiscoveryFailed::because('at least one migration source adapter is required.');
        }

        $owners = [];
        $migrations = [];

        foreach ($adapters as $adapter) {
            $discovery = $adapter->discover();
            array_push($owners, ...$discovery->owners);
            array_push($migrations, ...$discovery->migrations);
        }

        return new MigrationCatalog($owners, $migrations);
    }
}
