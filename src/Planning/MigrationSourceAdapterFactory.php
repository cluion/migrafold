<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Planning;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;
use Cluion\Migrafold\Discovery\LaravelMigrationSourceAdapter;
use Cluion\Migrafold\Discovery\MigrationSourceAdapter;
use Cluion\Migrafold\Discovery\ModuarkMigrationSourceAdapter;
use Cluion\Migrafold\Discovery\NwidartMigrationSourceAdapter;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;

final readonly class MigrationSourceAdapterFactory
{
    private const MODUARK_REGISTRY = 'Cluion\\Moduark\\Registry\\ModuleRegistry';

    private const MODUARK_RESOURCES = 'Cluion\\Moduark\\Resources\\ResourceManifest';

    private const MODUARK_TABLES = 'Cluion\\Moduark\\Persistence\\TableOwnershipIndex';

    private const NWIDART_REPOSITORY = 'Nwidart\\Modules\\Contracts\\RepositoryInterface';

    /** @return list<MigrationSourceAdapter> */
    public function forApplication(string $projectRoot, Container $container): array
    {
        $adapters = [new LaravelMigrationSourceAdapter($projectRoot)];
        $bindings = [self::MODUARK_REGISTRY, self::MODUARK_RESOURCES, self::MODUARK_TABLES];
        $available = array_values(array_filter(
            $bindings,
            static fn (string $binding): bool => $container->bound($binding),
        ));

        if ($available !== []) {
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
        }

        if ($container->bound(self::NWIDART_REPOSITORY)) {
            $repository = $container->make(self::NWIDART_REPOSITORY);

            if (! is_object($repository)) {
                throw MigrationDiscoveryFailed::because('nWidart repository binding must resolve to an object.');
            }

            $adapters[] = NwidartMigrationSourceAdapter::fromRuntime(
                $projectRoot,
                $repository,
                $this->nwidartTableOwnership($container),
            );
        }

        return $adapters;
    }

    /** @return array<mixed> */
    private function nwidartTableOwnership(Container $container): array
    {
        if (! $container->bound('config')) {
            throw MigrationDiscoveryFailed::because(
                'nWidart runtime integration requires Laravel configuration.',
            );
        }

        $config = $container->make('config');

        if (! $config instanceof ConfigRepository) {
            throw MigrationDiscoveryFailed::because(
                'nWidart runtime integration could not resolve Laravel configuration.',
            );
        }

        $ownership = $config->get('migrafold.nwidart.table_owners', []);

        if (! is_array($ownership)) {
            throw MigrationDiscoveryFailed::because(
                '[migrafold.nwidart.table_owners] must be an array.',
            );
        }

        return $ownership;
    }
}
