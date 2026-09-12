<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Planning;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;
use Cluion\Migrafold\Discovery\LaravelMigrationSourceAdapter;
use Cluion\Migrafold\Discovery\MigrationSourceAdapter;
use Cluion\Migrafold\Discovery\ModuarkMigrationSourceAdapter;
use Illuminate\Contracts\Container\Container;

final readonly class MigrationSourceAdapterFactory
{
    private const MODUARK_REGISTRY = 'Cluion\\Moduark\\Registry\\ModuleRegistry';

    private const MODUARK_RESOURCES = 'Cluion\\Moduark\\Resources\\ResourceManifest';

    private const MODUARK_TABLES = 'Cluion\\Moduark\\Persistence\\TableOwnershipIndex';

    /** @return list<MigrationSourceAdapter> */
    public function forApplication(string $projectRoot, Container $container): array
    {
        $adapters = [new LaravelMigrationSourceAdapter($projectRoot)];
        $bindings = [self::MODUARK_REGISTRY, self::MODUARK_RESOURCES, self::MODUARK_TABLES];
        $available = array_values(array_filter(
            $bindings,
            static fn (string $binding): bool => $container->bound($binding),
        ));

        if ($available === []) {
            return $adapters;
        }

        if (count($available) !== count($bindings)) {
            throw MigrationDiscoveryFailed::because(
                'Moduark runtime integration is partially bound; registry, resources, and table ownership are all required.',
            );
        }

        $registry = $container->make(self::MODUARK_REGISTRY);
        $resources = $container->make(self::MODUARK_RESOURCES);
        $tables = $container->make(self::MODUARK_TABLES);

        if (! is_object($registry) || ! is_object($resources) || ! is_object($tables)) {
            throw MigrationDiscoveryFailed::because('Moduark runtime bindings must resolve to objects.');
        }

        $adapters[] = ModuarkMigrationSourceAdapter::fromRuntime(
            $projectRoot,
            $registry,
            $resources,
            $tables,
        );

        return $adapters;
    }
}
