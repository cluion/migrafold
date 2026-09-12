<?php

declare(strict_types=1);

namespace Tests\Feature\Analysis;

use Cluion\Migrafold\Analysis\MigrationCatalogAnalyzer;
use Cluion\Migrafold\Analysis\MigrationClassification;
use Cluion\Migrafold\Discovery\DiscoveredMigration;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Discovery\MigrationOwner;
use Cluion\Migrafold\Output\SourceMigration;
use PHPUnit\Framework\TestCase;

final class MigrationCatalogAnalyzerTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            $this->removeDirectory($this->root);
        }

        parent::tearDown();
    }

    public function test_catalog_report_preserves_application_and_module_source_identity(): void
    {
        $root = $this->root();
        $applicationDirectory = $root.'/database/migrations';
        $moduleDirectory = $root.'/Modules/Billing/database/migrations';
        $application = $this->write(
            $applicationDirectory.'/2020_01_01_000000_create_users_table.php',
            $this->migration(<<<'PHP'
Schema::create('users', static function (Blueprint $table): void {
    $table->id();
});
PHP),
        );
        $module = $this->write(
            $moduleDirectory.'/2020_01_02_000000_seed_billing_defaults.php',
            $this->migration(<<<'PHP'
DB::table('billing_settings')->insert(['key' => 'currency', 'value' => 'TWD']);
PHP),
        );
        $applicationOwner = new MigrationOwner(
            id: 'laravel:application',
            name: 'application',
            migrationDirectory: $applicationDirectory,
            tables: [],
            fallback: true,
        );
        $moduleOwner = new MigrationOwner(
            id: 'moduark:Billing',
            name: 'Billing',
            migrationDirectory: $moduleDirectory,
            tables: ['billing_settings'],
        );
        $catalog = new MigrationCatalog(
            [$moduleOwner, $applicationOwner],
            [
                $this->discovered($root, $module, $moduleOwner),
                $this->discovered($root, $application, $applicationOwner),
            ],
        );
        $report = (new MigrationCatalogAnalyzer())->analyze($catalog);

        self::assertSame([
            '2020_01_01_000000_create_users_table',
            '2020_01_02_000000_seed_billing_defaults',
        ], array_map(
            static fn ($entry): string => $entry->migration->name,
            $report->entries,
        ));
        self::assertSame('laravel:application', $report->entries[0]->analysis->ownerId);
        self::assertSame(MigrationClassification::SchemaOnly, $report->entries[0]->analysis->classification);
        self::assertSame('moduark:Billing', $report->entries[1]->analysis->ownerId);
        self::assertSame(MigrationClassification::DataOnly, $report->entries[1]->analysis->classification);
        self::assertCount(1, $report->compactable());
        self::assertCount(1, $report->preserved());
        self::assertSame($module, $report->preserved()[0]->migration->absolutePath);
        self::assertSame([], $report->blocking());
    }

    private function discovered(
        string $root,
        string $path,
        MigrationOwner $owner,
    ): DiscoveredMigration {
        return new DiscoveredMigration(
            name: pathinfo($path, PATHINFO_FILENAME),
            absolutePath: $path,
            ownerId: $owner->id,
            source: SourceMigration::fromFile($root, $path),
        );
    }

    private function migration(string $body): string
    {
        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
{$body}
    }

    public function down(): void {}
};
PHP;
    }

    private function root(): string
    {
        $root = sys_get_temp_dir().'/migrafold-catalog-analysis-'.bin2hex(random_bytes(8));

        if (! mkdir($root, 0700)) {
            self::fail("Unable to create catalog analyzer fixture [{$root}].");
        }

        $this->root = $root;

        return $root;
    }

    private function write(string $path, string $contents): string
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            self::fail("Unable to create migration directory [{$directory}].");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("Unable to write migration fixture [{$path}].");
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
