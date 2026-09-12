<?php

declare(strict_types=1);

namespace Tests\Feature\Planning;

use Cluion\Migrafold\Discovery\Exception\MigrationDiscoveryFailed;
use Cluion\Migrafold\Discovery\LaravelMigrationSourceAdapter;
use Cluion\Migrafold\Discovery\MigrationDiscoverer;
use Cluion\Migrafold\Discovery\NwidartMigrationSourceAdapter;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Planning\MigrationSourceAdapterFactory;
use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Tests\TestCase;
use Tests\Support\MigrationSource;

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
            MigrationSource::users(),
        );
        $plan = $this->compactionPlanner()->plan(
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

    public function test_it_preserves_data_migration_files_and_records_outside_the_compacted_scope(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });
        $root = $this->root();
        $schemaSource = $this->write(
            $root.'/database/migrations/2020_01_01_000000_create_users_table.php',
            MigrationSource::users(),
        );
        $dataSource = $this->write(
            $root.'/database/migrations/2030_01_01_000000_seed_system_user.php',
            MigrationSource::insertUser(),
        );
        $plan = $this->compactionPlanner()->plan(
            $root,
            $this->connection(),
            [new LaravelMigrationSourceAdapter($root)],
            '2026_09_12',
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
        );
        $summary = $plan->summary();

        self::assertCount(2, $plan->catalog->migrations);
        self::assertSame([$schemaSource], array_column($plan->disposition->items, 'source'));
        self::assertSame(
            ['2020_01_01_000000_create_users_table'],
            $plan->activation->retiredNames(),
        );
        self::assertSame(
            ['2030_01_01_000000_seed_system_user'],
            $summary['analysis']['preserve'],
        );
        self::assertSame(['2020_01_01_000000_create_users_table'], $summary['analysis']['compact']);
        self::assertStringContainsString(
            '"data_state_compared":false',
            json_encode($summary['analysis'], JSON_THROW_ON_ERROR),
        );
        $manifest = json_decode(
            $plan->output->owners[0]->output->manifestContents,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        self::assertSame('migrafold-manifest-v2', $manifest['format_version']);
        self::assertSame([
            'mode' => 'same-engine-dual-sandbox',
            'driver' => 'sqlite',
            'schema_fingerprints' => [
                'source_replay' => $plan->snapshot->fingerprint(),
                'baseline_replay' => $plan->snapshot->fingerprint(),
                'current_database' => $plan->snapshot->fingerprint(),
            ],
            'migration_counts' => [
                'source' => 2,
                'baseline' => 1,
                'preserved' => 1,
            ],
            'data_state_compared' => false,
        ], $manifest['verification']);
        self::assertSame([
            'compacted' => [[
                'name' => '2020_01_01_000000_create_users_table',
                'owner' => 'laravel:application',
                'path' => 'database/migrations/2020_01_01_000000_create_users_table.php',
                'sha256' => hash_file('sha256', $schemaSource),
                'classification' => 'schema_only',
                'action' => 'compact',
            ]],
            'preserved' => [[
                'name' => '2030_01_01_000000_seed_system_user',
                'owner' => 'laravel:application',
                'path' => 'database/migrations/2030_01_01_000000_seed_system_user.php',
                'sha256' => hash_file('sha256', $dataSource),
                'classification' => 'data_only',
                'action' => 'preserve',
            ]],
        ], $manifest['migration_scope']);
        self::assertFileExists($dataSource);
        self::assertStringNotContainsString($dataSource, json_encode($plan->disposition, JSON_THROW_ON_ERROR));
    }

    public function test_it_rejects_a_current_database_that_differs_from_source_replay(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        $root = $this->root();
        $this->write(
            $root.'/database/migrations/2020_01_01_000000_create_users_table.php',
            MigrationSource::users(),
        );

        $this->expectException(ReplayVerificationFailed::class);
        $this->expectExceptionMessage('does not match current database schema fingerprint');

        $this->compactionPlanner()->plan(
            $root,
            $this->connection(),
            [new LaravelMigrationSourceAdapter($root)],
            '2026_09_12',
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
        );
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

    public function test_adapter_factory_adds_nwidart_runtime_with_configured_ownership(): void
    {
        $root = $this->root();
        $moduleRoot = $root.'/Modules/Billing';
        $this->write(
            $moduleRoot.'/database/migrations/2020_01_01_000000_create_invoices_table.php',
            "<?php\n",
        );
        $module = new class($moduleRoot)
        {
            public function __construct(private readonly string $path) {}

            public function getName(): string
            {
                return 'Billing';
            }

            public function getPath(): string
            {
                return $this->path;
            }
        };
        $repository = new class($module)
        {
            public function __construct(private readonly object $module) {}

            /** @return array<string, object> */
            public function allEnabled(): array
            {
                return ['billing' => $this->module];
            }

            public function config(string $key, mixed $default = null): mixed
            {
                return $key === 'paths.generator.migration.path'
                    ? 'database/migrations'
                    : $default;
            }
        };
        $container = new Container();
        $container->instance('Nwidart\\Modules\\Contracts\\RepositoryInterface', $repository);
        $container->instance('config', new ConfigRepository([
            'migrafold' => [
                'nwidart' => [
                    'table_owners' => ['invoices' => 'Billing'],
                ],
            ],
        ]));

        $adapters = (new MigrationSourceAdapterFactory())->forApplication($root, $container);
        $catalog = (new MigrationDiscoverer())->discover($adapters);

        self::assertCount(2, $adapters);
        self::assertSame(['laravel:application', 'nwidart:Billing'], array_map(
            static fn ($owner): string => $owner->id,
            $catalog->owners,
        ));
        self::assertSame('nwidart:Billing', $catalog->ownerForTable('invoices')->id);
    }

    public function test_nwidart_owned_tables_plan_outputs_and_source_retirement_in_the_module(): void
    {
        Schema::create('invoices', static function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
        });
        $root = $this->root();
        $moduleRoot = $root.'/Modules/Billing';
        $source = $this->write(
            $moduleRoot.'/database/migrations/2020_01_01_000000_create_invoices_table.php',
            MigrationSource::invoices(),
        );
        $plan = $this->compactionPlanner()->plan(
            $root,
            $this->connection(),
            [
                new LaravelMigrationSourceAdapter($root),
                NwidartMigrationSourceAdapter::fromPayloads(
                    $root,
                    [['name' => 'Billing', 'path' => $moduleRoot]],
                    ['invoices' => 'Billing'],
                ),
            ],
            '2026_09_12',
            SourceDispositionMode::Archive,
            '2026-09-12T120000Z',
        );

        self::assertSame('nwidart:Billing', $plan->output->owners[0]->ownerId);
        self::assertSame(dirname($source), $plan->output->owners[0]->output->directory);
        self::assertSame('nwidart:Billing', $plan->disposition->items[0]->ownerId);
        self::assertSame($source, $plan->disposition->items[0]->source);
        self::assertNotNull($plan->disposition->items[0]->destination);
        self::assertStringStartsWith(dirname($source).'/.migrafold-archive/', $plan->disposition->items[0]->destination);
        self::assertFileDoesNotExist(
            dirname($source).'/2026_09_12_000001_create_invoices_baseline.php',
        );
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
