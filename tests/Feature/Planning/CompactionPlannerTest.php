<?php

declare(strict_types=1);

namespace Tests\Feature\Planning;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;
use Cluion\Migrafold\Discovery\LaravelMigrationSourceAdapter;
use Cluion\Migrafold\Discovery\MigrationDiscoverer;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Planning\CompactionPlanner;
use Cluion\Migrafold\Planning\MigrationSourceAdapterFactory;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Tests\TestCase;

final class CompactionPlannerTest extends TestCase
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

    public function test_it_builds_an_end_to_end_read_only_plan(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });
        $root = $this->root();
        $source = $this->write(
            $root.'/database/migrations/2020_01_01_000000_create_users_table.php',
            "<?php\n// source migration\n",
        );
        $plan = (new CompactionPlanner())->plan(
            $root,
            $this->connection(),
            [new LaravelMigrationSourceAdapter($root)],
            '2026_09_12',
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
        );
        $summary = $plan->summary();

        self::assertSame('sqlite', $summary['schema']['driver']);
        self::assertSame(1, $summary['schema']['tables']);
        self::assertSame('archive', $summary['source_disposition']);
        self::assertSame(['2020_01_01_000000_create_users_table'], $summary['records']['retire']);
        self::assertSame(['2026_09_12_000001_create_users_baseline'], $summary['records']['activate']);
        self::assertSame('laravel:application', $summary['owners'][0]['id']);
        self::assertSame([$source], [$plan->catalog->migrations[0]->absolutePath]);
        self::assertFileExists($source);
        self::assertFileDoesNotExist(
            $root.'/database/migrations/2026_09_12_000001_create_users_baseline.php',
        );
        self::assertDirectoryDoesNotExist($root.'/database/migrations/.migrafold-archive');
        self::assertSame(0, $this->connection()->table('migrations')->count());
    }

    public function test_adapter_factory_adds_complete_moduark_runtime_bindings(): void
    {
        $root = $this->root();
        $moduleFile = $this->write($root.'/app/Modules/Billing/BillingModule.php', "<?php\n");
        $migration = $this->write(
            $root.'/app/Modules/Billing/Database/Migrations/2020_01_01_000000_create_invoices_table.php',
            "<?php\n",
        );
        $class = 'App\\Modules\\Billing\\BillingModule';
        $container = new Container();
        $container->instance(
            'Cluion\\Moduark\\Registry\\ModuleRegistry',
            $this->payloadObject('toArray', [[
                'name' => 'Billing',
                'class' => $class,
                'path' => $moduleFile,
            ]]),
        );
        $container->instance(
            'Cluion\\Moduark\\Resources\\ResourceManifest',
            $this->payloadObject('toArray', [
                'resources' => [[
                    'plugin' => 'migrations',
                    'module' => $class,
                    'source' => dirname($migration),
                ]],
            ]),
        );
        $container->instance(
            'Cluion\\Moduark\\Persistence\\TableOwnershipIndex',
            $this->payloadObject('all', ['invoices' => $class]),
        );

        $adapters = (new MigrationSourceAdapterFactory())->forApplication($root, $container);
        $catalog = (new MigrationDiscoverer())->discover($adapters);

        self::assertCount(2, $adapters);
        self::assertSame(['laravel:application', 'moduark:Billing'], array_map(
            static fn ($owner): string => $owner->id,
            $catalog->owners,
        ));
        self::assertSame('moduark:Billing', $catalog->ownerForTable('invoices')->id);
    }

    public function test_adapter_factory_rejects_partial_moduark_runtime_bindings(): void
    {
        $root = $this->root();
        $container = new Container();
        $container->instance(
            'Cluion\\Moduark\\Registry\\ModuleRegistry',
            $this->payloadObject('toArray', []),
        );

        $this->expectException(MigrationDiscoveryFailed::class);
        $this->expectExceptionMessage('partially bound');

        (new MigrationSourceAdapterFactory())->forApplication($root, $container);
    }

    /** @param array<mixed> $payload */
    private function payloadObject(string $method, array $payload): object
    {
        return new class($method, $payload)
        {
            /** @param array<mixed> $payload */
            public function __construct(
                private readonly string $method,
                private readonly array $payload,
            ) {}

            /** @return array<mixed> */
            public function toArray(): array
            {
                if ($this->method !== 'toArray') {
                    PHPUnitTestCase::fail('Unexpected toArray call.');
                }

                return $this->payload;
            }

            /** @return array<mixed> */
            public function all(): array
            {
                if ($this->method !== 'all') {
                    PHPUnitTestCase::fail('Unexpected all call.');
                }

                return $this->payload;
            }
        };
    }

    private function root(): string
    {
        $root = sys_get_temp_dir().'/migrafold-planning-'.bin2hex(random_bytes(8));

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

    private function write(string $path, string $contents): string
    {
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
