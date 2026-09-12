<?php

declare(strict_types=1);

namespace Tests\Feature\Verification;

use Cluion\Migrafold\Discovery\MigrationOwner;
use Cluion\Migrafold\Verification\CompactionManifestReader;
use Cluion\Migrafold\Verification\Exception\ManifestVerificationFailed;
use PHPUnit\Framework\TestCase;

final class CompactionManifestReaderTest extends TestCase
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

    public function test_it_reads_a_valid_v2_manifest_into_typed_metadata(): void
    {
        [$root, $owner] = $this->fixture();
        $this->writeManifest($owner, $this->manifest());

        $manifest = (new CompactionManifestReader())->read($root, $owner);

        self::assertNotNull($manifest);
        self::assertSame('laravel:application', $manifest->ownerId);
        self::assertSame('sqlite', $manifest->driver);
        self::assertSame(['2020_01_01_000000_create_users_table'], array_column(
            array_map(static fn ($migration): array => $migration->toArray(), $manifest->compacted),
            'name',
        ));
        self::assertSame(['2026_09_12_000001_create_users_baseline'], array_column(
            array_map(static fn ($output): array => ['name' => $output->name], $manifest->outputs),
            'name',
        ));
    }

    public function test_it_reads_postgres_replay_metadata(): void
    {
        [$root, $owner] = $this->fixture();
        $payload = $this->manifest();
        $schema = $payload['schema'] ?? null;
        $verification = $payload['verification'] ?? null;
        self::assertIsArray($schema);
        self::assertIsArray($verification);
        $schema['driver'] = 'pgsql';
        $verification['driver'] = 'pgsql';
        $payload['schema'] = $schema;
        $payload['verification'] = $verification;
        $this->writeManifest($owner, $payload);

        $manifest = (new CompactionManifestReader())->read($root, $owner);

        self::assertNotNull($manifest);
        self::assertSame('pgsql', $manifest->driver);
    }

    public function test_it_rejects_a_manifest_whose_owner_does_not_match_discovery(): void
    {
        [$root, $owner] = $this->fixture();
        $payload = $this->manifest();
        $manifestOwner = $payload['owner'] ?? null;
        self::assertIsArray($manifestOwner);
        $manifestOwner['id'] = 'moduark:Billing';
        $payload['owner'] = $manifestOwner;
        $this->writeManifest($owner, $payload);

        $this->expectException(ManifestVerificationFailed::class);
        $this->expectExceptionMessage('does not match discovered owner');

        (new CompactionManifestReader())->read($root, $owner);
    }

    public function test_it_rejects_inconsistent_counts_before_files_or_database_are_checked(): void
    {
        [$root, $owner] = $this->fixture();
        $payload = $this->manifest();
        $verification = $payload['verification'] ?? null;
        self::assertIsArray($verification);
        $counts = $verification['migration_counts'] ?? null;
        self::assertIsArray($counts);
        $counts['source'] = 2;
        $verification['migration_counts'] = $counts;
        $payload['verification'] = $verification;
        $this->writeManifest($owner, $payload);

        $this->expectException(ManifestVerificationFailed::class);
        $this->expectExceptionMessage('inconsistent migration counts');

        (new CompactionManifestReader())->read($root, $owner);
    }

    /** @return array{0: string, 1: MigrationOwner} */
    private function fixture(): array
    {
        $root = sys_get_temp_dir().'/migrafold-manifest-reader-'.bin2hex(random_bytes(8));
        $directory = $root.'/database/migrations';

        if (! mkdir($directory, 0700, true)) {
            self::fail("Unable to create fixture directory [{$directory}].");
        }

        $resolved = realpath($root);

        if ($resolved === false) {
            self::fail("Unable to resolve fixture root [{$root}].");
        }

        $this->roots[] = $resolved;

        return [
            $resolved,
            new MigrationOwner(
                'laravel:application',
                'application',
                $resolved.'/database/migrations',
                [],
                true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $schema = str_repeat('a', 64);

        return [
            'format_version' => 'migrafold-manifest-v2',
            'schema' => [
                'format_version' => '1',
                'driver' => 'sqlite',
                'sha256' => $schema,
            ],
            'sources' => [[
                'path' => 'database/migrations/2020_01_01_000000_create_users_table.php',
                'sha256' => str_repeat('b', 64),
            ]],
            'outputs' => [[
                'path' => '2026_09_12_000001_create_users_baseline.php',
                'table' => 'users',
                'sha256' => str_repeat('c', 64),
            ]],
            'owner' => [
                'id' => 'laravel:application',
                'name' => 'application',
            ],
            'verification' => [
                'mode' => 'same-engine-dual-sandbox',
                'driver' => 'sqlite',
                'schema_fingerprints' => [
                    'source_replay' => $schema,
                    'baseline_replay' => $schema,
                    'current_database' => $schema,
                ],
                'migration_counts' => [
                    'source' => 1,
                    'baseline' => 1,
                    'preserved' => 0,
                ],
                'data_state_compared' => false,
            ],
            'migration_scope' => [
                'compacted' => [[
                    'name' => '2020_01_01_000000_create_users_table',
                    'owner' => 'laravel:application',
                    'path' => 'database/migrations/2020_01_01_000000_create_users_table.php',
                    'sha256' => str_repeat('b', 64),
                    'classification' => 'schema_only',
                    'action' => 'compact',
                ]],
                'preserved' => [],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function writeManifest(MigrationOwner $owner, array $payload): void
    {
        $written = file_put_contents(
            $owner->migrationDirectory.'/.migrafold-manifest.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
        );

        self::assertNotFalse($written);
    }

    private function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);

        if ($entries === false) {
            self::fail("Unable to inspect fixture directory [{$directory}].");
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
