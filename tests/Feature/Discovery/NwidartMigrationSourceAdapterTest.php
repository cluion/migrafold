<?php

declare(strict_types=1);

namespace Tests\Feature\Discovery;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;
use Cluion\Migrafold\Discovery\LaravelMigrationSourceAdapter;
use Cluion\Migrafold\Discovery\MigrationDiscoverer;
use Cluion\Migrafold\Discovery\NwidartMigrationSourceAdapter;
use PHPUnit\Framework\TestCase;

final class NwidartMigrationSourceAdapterTest extends TestCase
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

    public function test_runtime_discovers_enabled_modules_and_configured_generator_path(): void
    {
        $root = $this->root();
        $this->write($root, 'database/migrations/2020_01_01_000000_create_users_table.php');
        $moduleRoot = $root.'/Modules/Billing';
        $migration = $this->write(
            $root,
            'Modules/Billing/Database/Migrations/2020_01_02_000000_create_invoices_table.php',
        );
        $module = $this->module('Billing', $moduleRoot);
        $repository = $this->repository(['billing' => $module], 'Database/Migrations');
        $adapter = NwidartMigrationSourceAdapter::fromRuntime(
            $root,
            $repository,
            ['invoices' => 'Billing'],
        );
        $catalog = (new MigrationDiscoverer())->discover([
            new LaravelMigrationSourceAdapter($root),
            $adapter,
        ]);

        self::assertSame(['laravel:application', 'nwidart:Billing'], array_map(
            static fn ($owner): string => $owner->id,
            $catalog->owners,
        ));
        self::assertSame('nwidart:Billing', $catalog->ownerForTable('INVOICES')->id);
        self::assertSame(dirname($migration), $catalog->owners[1]->migrationDirectory);
        self::assertSame(
            'Modules/Billing/Database/Migrations/2020_01_02_000000_create_invoices_table.php',
            $catalog->sourcesFor('nwidart:Billing')[0]->path,
        );
    }

    public function test_module_migrations_without_explicit_table_ownership_fail_closed(): void
    {
        $root = $this->root();
        $moduleRoot = $root.'/Modules/Billing';
        $this->write(
            $root,
            'Modules/Billing/database/migrations/2020_01_02_000000_create_invoices_table.php',
        );
        $adapter = NwidartMigrationSourceAdapter::fromPayloads(
            $root,
            [['name' => 'Billing', 'path' => $moduleRoot]],
            [],
        );

        $this->expectException(MigrationDiscoveryFailed::class);
        $this->expectExceptionMessage('no explicit table ownership');

        $adapter->discover();
    }

    public function test_ownership_cannot_reference_an_inactive_or_unknown_module(): void
    {
        $root = $this->root();
        $this->write($root, 'Modules/Billing/module.json');

        $this->expectException(MigrationDiscoveryFailed::class);
        $this->expectExceptionMessage('references inactive or unknown Module [Orders]');

        NwidartMigrationSourceAdapter::fromPayloads(
            $root,
            [['name' => 'Billing', 'path' => $root.'/Modules/Billing']],
            ['orders' => 'Orders'],
        );
    }

    public function test_vendor_owned_modules_are_rejected_as_output_targets(): void
    {
        $root = $this->root();
        $this->write($root, 'vendor/acme/billing/module.json');

        $this->expectException(MigrationDiscoveryFailed::class);
        $this->expectExceptionMessage('is vendor-owned and cannot receive generated migrations');

        NwidartMigrationSourceAdapter::fromPayloads(
            $root,
            [['name' => 'Billing', 'path' => $root.'/vendor/acme/billing']],
            ['invoices' => 'Billing'],
        );
    }

    public function test_generator_path_must_be_module_relative(): void
    {
        $root = $this->root();
        $this->write($root, 'Modules/Billing/module.json');

        $this->expectException(MigrationDiscoveryFailed::class);
        $this->expectExceptionMessage('must be Module-relative');

        NwidartMigrationSourceAdapter::fromPayloads(
            $root,
            [['name' => 'Billing', 'path' => $root.'/Modules/Billing']],
            ['invoices' => 'Billing'],
            '../database/migrations',
        );
    }

    private function module(string $name, string $path): object
    {
        return new class($name, $path)
        {
            public function __construct(
                private readonly string $name,
                private readonly string $path,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getPath(): string
            {
                return $this->path;
            }
        };
    }

    /** @param array<string, object> $modules */
    private function repository(array $modules, string $migrationPath): object
    {
        return new class($modules, $migrationPath)
        {
            /** @param array<string, object> $modules */
            public function __construct(
                private readonly array $modules,
                private readonly string $migrationPath,
            ) {}

            /** @return array<string, object> */
            public function allEnabled(): array
            {
                return $this->modules;
            }

            public function config(string $key, mixed $default = null): mixed
            {
                return $key === 'paths.generator.migration.path'
                    ? $this->migrationPath
                    : $default;
            }
        };
    }

    private function root(): string
    {
        $root = sys_get_temp_dir().'/migrafold-nwidart-'.bin2hex(random_bytes(8));

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

    private function write(string $root, string $relativePath): string
    {
        $path = $root.'/'.str_replace('\\', '/', $relativePath);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            self::fail("Unable to create fixture directory [{$directory}].");
        }

        if (file_put_contents($path, "<?php\n// fixture\n") === false) {
            self::fail("Unable to write fixture [{$path}].");
        }

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
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
