<?php

declare(strict_types=1);

namespace Tests\Feature\Discovery;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;
use Cluion\Migrafold\Discovery\LaravelMigrationSourceAdapter;
use Cluion\Migrafold\Discovery\MigrationDiscoverer;
use Cluion\Migrafold\Discovery\ModuarkMigrationSourceAdapter;
use PHPUnit\Framework\TestCase;

final class MigrationDiscovererTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->roots) as $root) {
            $this->removeDirectory($root);
        }

        parent::tearDown();
    }

    public function test_laravel_adapter_discovers_only_direct_migration_files_with_fingerprints(): void
    {
        $root = $this->root();
        $first = $this->write($root, 'database/migrations/2020_01_01_000000_create_users_table.php', "<?php\n// users\n");
        $this->write($root, 'database/migrations/readme.php', "<?php\n");
        $this->write($root, 'database/migrations/nested/2020_01_02_000000_nested.php', "<?php\n");
        $catalog = (new MigrationDiscoverer())->discover([
            new LaravelMigrationSourceAdapter($root),
        ]);

        self::assertCount(1, $catalog->owners);
        self::assertSame('laravel:application', $catalog->owners[0]->id);
        self::assertSame($root.'/database/migrations', $catalog->owners[0]->migrationDirectory);
        self::assertCount(1, $catalog->migrations);
        self::assertSame('2020_01_01_000000_create_users_table', $catalog->migrations[0]->name);
        self::assertSame($first, $catalog->migrations[0]->absolutePath);
        self::assertSame('database/migrations/2020_01_01_000000_create_users_table.php', $catalog->migrations[0]->source->path);
        self::assertSame(hash_file('sha256', $first), $catalog->migrations[0]->source->sha256);
        self::assertSame('laravel:application', $catalog->ownerForTable('users')->id);
    }

    public function test_moduark_runtime_payload_maps_active_module_sources_and_explicit_table_ownership(): void
    {
        $root = $this->root();
        $this->write($root, 'database/migrations/2020_01_01_000000_create_users_table.php', "<?php\n");
        $moduleFile = $this->write($root, 'app/Modules/Billing/BillingModule.php', "<?php\n");
        $migration = $this->write(
            $root,
            'app/Modules/Billing/Database/Migrations/2020_01_02_000000_create_invoices_table.php',
            "<?php\n// invoices\n",
        );
        $class = 'App\\Modules\\Billing\\BillingModule';
        $moduark = ModuarkMigrationSourceAdapter::fromPayloads(
            $root,
            [$this->registryEntry('Billing', $class, $moduleFile)],
            $this->resourceManifest($class, dirname($migration)),
            ['invoices' => $class],
        );
        $catalog = (new MigrationDiscoverer())->discover([
            new LaravelMigrationSourceAdapter($root),
            $moduark,
        ]);

        self::assertSame(['laravel:application', 'moduark:Billing'], array_map(
            static fn ($owner): string => $owner->id,
            $catalog->owners,
        ));
        self::assertSame('moduark:Billing', $catalog->ownerForTable('INVOICES')->id);
        self::assertSame('app/Modules/Billing/Database/Migrations/2020_01_02_000000_create_invoices_table.php', $catalog->sourcesFor('moduark:Billing')[0]->path);
        self::assertSame('laravel:application', $catalog->migrations[0]->ownerId);
        self::assertSame('moduark:Billing', $catalog->migrations[1]->ownerId);
        self::assertSame('laravel:application', $catalog->ownerForTable('audit_events')->id);
    }

    public function test_moduark_runtime_objects_and_lowercase_migration_convention_are_supported(): void
    {
        $root = $this->root();
        $moduleFile = $this->write($root, 'app/Modules/Order/app/OrderModule.php', "<?php\n");
        $migration = $this->write(
            $root,
            'app/Modules/Order/database/migrations/2020_01_01_000000_create_orders_table.php',
            "<?php\n",
        );
        $class = 'App\\Modules\\Order\\OrderModule';
        $registryPayload = [$this->registryEntry('Order', $class, $moduleFile)];
        $resourcePayload = $this->resourceManifest($class, dirname($migration));
        $ownershipPayload = ['orders' => $class];
        $registry = new class($registryPayload)
        {
            /** @param array<mixed> $payload */
            public function __construct(private readonly array $payload) {}

            /** @return array<mixed> */
            public function toArray(): array
            {
                return $this->payload;
            }
        };
        $resources = new class($resourcePayload)
        {
            /** @param array<mixed> $payload */
            public function __construct(private readonly array $payload) {}

            /** @return array<mixed> */
            public function toArray(): array
            {
                return $this->payload;
            }
        };
        $ownership = new class($ownershipPayload)
        {
            /** @param array<mixed> $payload */
            public function __construct(private readonly array $payload) {}

            /** @return array<mixed> */
            public function all(): array
            {
                return $this->payload;
            }
        };

        $catalog = (new MigrationDiscoverer())->discover([
            ModuarkMigrationSourceAdapter::fromRuntime($root, $registry, $resources, $ownership),
        ]);

        self::assertSame('moduark:Order', $catalog->ownerForTable('orders')->id);
        self::assertSame(dirname($migration), $catalog->owners[0]->migrationDirectory);
        self::assertSame('app/Modules/Order/database/migrations/2020_01_01_000000_create_orders_table.php', $catalog->migrations[0]->source->path);
    }

    public function test_duplicate_migration_names_across_owners_fail_closed(): void
    {
        $root = $this->root();
        $name = '2020_01_01_000000_create_users_table.php';
        $this->write($root, 'database/migrations/'.$name, "<?php\n// app\n");
        $moduleFile = $this->write($root, 'app/Modules/User/UserModule.php', "<?php\n");
        $moduleMigration = $this->write($root, 'app/Modules/User/Database/Migrations/'.$name, "<?php\n// module\n");
        $class = 'App\\Modules\\User\\UserModule';

        $this->expectException(MigrationDiscoveryFailed::class);
        $this->expectExceptionMessage('migration name [2020_01_01_000000_create_users_table] is duplicated');

        (new MigrationDiscoverer())->discover([
            new LaravelMigrationSourceAdapter($root),
            ModuarkMigrationSourceAdapter::fromPayloads(
                $root,
                [$this->registryEntry('User', $class, $moduleFile)],
                $this->resourceManifest($class, dirname($moduleMigration)),
                ['users' => $class],
            ),
        ]);
    }

    public function test_duplicate_table_claims_across_application_and_module_fail_closed(): void
    {
        $root = $this->root();
        $moduleFile = $this->write($root, 'app/Modules/User/UserModule.php', "<?php\n");
        $class = 'App\\Modules\\User\\UserModule';

        $this->expectException(MigrationDiscoveryFailed::class);
        $this->expectExceptionMessage('table [users] is claimed by both');

        (new MigrationDiscoverer())->discover([
            new LaravelMigrationSourceAdapter($root, tables: ['users']),
            ModuarkMigrationSourceAdapter::fromPayloads(
                $root,
                [$this->registryEntry('User', $class, $moduleFile)],
                ['resources' => []],
                ['users' => $class],
            ),
        ]);
    }

    public function test_unclaimed_table_without_application_fallback_fails_closed(): void
    {
        $root = $this->root();
        $orderFile = $this->write($root, 'app/Modules/Order/OrderModule.php', "<?php\n");
        $userFile = $this->write($root, 'app/Modules/User/UserModule.php', "<?php\n");
        $adapter = ModuarkMigrationSourceAdapter::fromPayloads(
            $root,
            [
                $this->registryEntry('Order', 'App\\Modules\\Order\\OrderModule', $orderFile),
                $this->registryEntry('User', 'App\\Modules\\User\\UserModule', $userFile),
            ],
            ['resources' => []],
            [],
        );
        $catalog = (new MigrationDiscoverer())->discover([$adapter]);

        $this->expectException(MigrationDiscoveryFailed::class);
        $this->expectExceptionMessage('table [audit_events] has no explicit owner in a multi-owner project');
        $catalog->ownerForTable('audit_events');
    }

    public function test_vendor_owned_moduark_modules_are_rejected_as_output_targets(): void
    {
        $root = $this->root();
        $this->write($root, 'vendor/acme/orders/composer.json', "{}\n");
        $moduleFile = $this->write($root, 'vendor/acme/orders/src/OrderModule.php', "<?php\n");

        $this->expectException(MigrationDiscoveryFailed::class);
        $this->expectExceptionMessage('is vendor-owned and cannot receive generated migrations');

        ModuarkMigrationSourceAdapter::fromPayloads(
            $root,
            [$this->registryEntry('Order', 'Acme\\Orders\\OrderModule', $moduleFile)],
            ['resources' => []],
            [],
        );
    }

    /** @return array{name: string, class: string, path: string, namespace: string} */
    private function registryEntry(string $name, string $class, string $path): array
    {
        return [
            'name' => $name,
            'class' => $class,
            'path' => $path,
            'namespace' => substr($class, 0, (int) strrpos($class, '\\')),
        ];
    }

    /** @return array{resources: list<array{plugin: string, module: string, source: string}>} */
    private function resourceManifest(string $class, string $directory): array
    {
        return [
            'resources' => [[
                'plugin' => 'migrations',
                'module' => $class,
                'source' => $directory,
            ]],
        ];
    }

    private function root(): string
    {
        $root = sys_get_temp_dir().'/migrafold-discovery-'.bin2hex(random_bytes(8));

        if (! mkdir($root, 0700)) {
            self::fail("Unable to create temporary root [{$root}].");
        }

        $resolved = realpath($root);

        if ($resolved === false) {
            self::fail("Unable to resolve temporary root [{$root}].");
        }

        $this->roots[] = $resolved;

        return $resolved;
    }

    private function write(string $root, string $relativePath, string $contents): string
    {
        $path = $root.'/'.str_replace('\\', '/', $relativePath);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            self::fail("Unable to create fixture directory [{$directory}].");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("Unable to write fixture [{$path}].");
        }

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            if (is_link($directory)) {
                unlink($directory);
            }

            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            self::fail("Unable to inspect temporary directory [{$directory}].");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
