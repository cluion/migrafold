<?php

declare(strict_types=1);

namespace Tests\Feature\Replay;

use Cluion\Migrafold\Discovery\DiscoveredMigration;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Discovery\MigrationOwner;
use Cluion\Migrafold\Output\SourceMigration;
use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Cluion\Migrafold\Replay\SchemaReplayComparator;
use Cluion\Migrafold\Replay\SqliteReplayVerifier;
use Cluion\Migrafold\Schema\SqliteSchemaInspector;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class SqliteReplayVerifierTest extends TestCase
{
    public function test_it_replays_sources_and_generated_baselines_in_separate_sqlite_sandboxes(): void
    {
        Schema::create('sentinels', static function (Blueprint $table): void {
            $table->id();
        });

        $catalog = $this->catalog([
            '2020_01_01_000000_create_users_table' => $this->createUsersMigration(),
            '2020_01_02_000000_add_nickname_to_users_table' => $this->addNicknameMigration(),
        ]);
        $temporaryRoot = $this->migrationDirectory([]);
        $databases = $this->databases();
        $defaultConnection = $databases->getDefaultConnection();
        $result = $this->verifier($databases, $temporaryRoot)->verify(
            $catalog,
            '2026_09_12',
        );

        self::assertSame($result->source->toJson(), $result->baseline->toJson());
        self::assertSame($result->source->fingerprint(), $result->fingerprint());
        self::assertSame(2, $result->sourceMigrations);
        self::assertSame(1, $result->baselineMigrations);
        self::assertCount(1, $result->baselines);
        self::assertSame('users', $result->baselines[0]->table);
        self::assertSame($defaultConnection, $databases->getDefaultConnection());
        self::assertTrue(Schema::hasTable('sentinels'));
        self::assertFalse(Schema::hasTable('users'));
        $this->assertDirectoryIsEmpty($temporaryRoot);
    }

    public function test_source_replay_failure_restores_the_default_connection_and_removes_workspace(): void
    {
        Schema::create('sentinels', static function (Blueprint $table): void {
            $table->id();
        });

        $catalog = $this->catalog([
            '2020_01_01_000000_failing_migration' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new \RuntimeException('fixture replay failure');
    }

    public function down(): void {}
};
PHP,
        ]);
        $temporaryRoot = $this->migrationDirectory([]);
        $databases = $this->databases();
        $defaultConnection = $databases->getDefaultConnection();

        try {
            $this->verifier($databases, $temporaryRoot)->verify($catalog, '2026_09_12');
            self::fail('Source replay unexpectedly succeeded.');
        } catch (ReplayVerificationFailed $exception) {
            self::assertStringContainsString('source sandbox replay raised', $exception->getMessage());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }

        self::assertSame($defaultConnection, $databases->getDefaultConnection());
        self::assertTrue(Schema::hasTable('sentinels'));
        $this->assertDirectoryIsEmpty($temporaryRoot);
    }

    public function test_source_fingerprint_drift_fails_before_a_workspace_is_created(): void
    {
        $catalog = $this->catalog([
            '2020_01_01_000000_create_users_table' => $this->createUsersMigration(),
        ]);
        $temporaryRoot = $this->migrationDirectory([]);
        $source = $catalog->migrations[0]->absolutePath;

        if (file_put_contents($source, "\n// changed\n", FILE_APPEND) === false) {
            self::fail('Unable to mutate replay fixture.');
        }

        try {
            $this->verifier($this->databases(), $temporaryRoot)->verify($catalog, '2026_09_12');
            self::fail('Fingerprint drift unexpectedly passed verification.');
        } catch (ReplayVerificationFailed $exception) {
            self::assertStringContainsString('changed after discovery', $exception->getMessage());
        }

        $this->assertDirectoryIsEmpty($temporaryRoot);
    }

    public function test_schema_comparison_rejects_a_fingerprint_mismatch(): void
    {
        $inspector = new SqliteSchemaInspector();
        $source = $inspector->inspect($this->connection());

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });

        $baseline = $inspector->inspect($this->connection());

        $this->expectException(ReplayVerificationFailed::class);
        $this->expectExceptionMessage('source schema fingerprint');

        (new SchemaReplayComparator())->assertEquivalent($source, $baseline);
    }

    /**
     * @param array<string, string> $migrations
     */
    private function catalog(array $migrations): MigrationCatalog
    {
        $directory = $this->migrationDirectory($migrations);
        $root = dirname($directory);
        $owner = new MigrationOwner(
            id: 'laravel:application',
            name: 'application',
            migrationDirectory: $directory,
            tables: [],
            fallback: true,
        );
        $discovered = [];

        foreach (array_keys($migrations) as $name) {
            $path = $directory.'/'.$name.'.php';

            $discovered[] = new DiscoveredMigration(
                name: $name,
                absolutePath: $path,
                ownerId: $owner->id,
                source: SourceMigration::fromFile($root, $path),
            );
        }

        return new MigrationCatalog([$owner], $discovered);
    }

    private function verifier(DatabaseManager $databases, string $temporaryRoot): SqliteReplayVerifier
    {
        $files = $this->app?->make('files');

        if (! $files instanceof Filesystem) {
            self::fail('Laravel filesystem service is unavailable.');
        }

        return new SqliteReplayVerifier(
            databases: $databases,
            files: $files,
            temporaryRoot: $temporaryRoot,
        );
    }

    private function databases(): DatabaseManager
    {
        $databases = $this->app?->make('db');

        if (! $databases instanceof DatabaseManager) {
            self::fail('Laravel database manager is unavailable.');
        }

        return $databases;
    }

    private function assertDirectoryIsEmpty(string $directory): void
    {
        $entries = scandir($directory);

        if ($entries === false) {
            self::fail("Unable to inspect temporary directory [{$directory}].");
        }

        self::assertSame([], array_values(array_diff($entries, ['.', '..'])));
    }

    private function createUsersMigration(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
PHP;
    }

    private function addNicknameMigration(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->string('nickname')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn('nickname');
        });
    }
};
PHP;
    }
}
