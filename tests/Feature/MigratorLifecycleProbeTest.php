<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class MigratorLifecycleProbeTest extends TestCase
{
    public function test_preloading_a_later_record_does_not_change_the_pending_set(): void
    {
        $later = '2026_01_01_000002_create_pending_probe_table';
        $directory = $this->migrationDirectory([
            '2026_01_01_000001_preload_later_record' => $this->migration(<<<PHP
DB::table('migrations')->insert([
    'migration' => '{$later}',
    'batch' => 99,
]);
PHP),
            $later => $this->migration(<<<'PHP'
Schema::create('pending_probe', function (Blueprint $table): void {
    $table->id();
});
PHP),
        ]);

        $this->migrator()->run($directory);

        self::assertTrue(Schema::hasTable('pending_probe'));
        self::assertSame(2, DB::table('migrations')->where('migration', $later)->count());
    }

    public function test_each_successful_migration_is_logged_before_the_next_one_runs(): void
    {
        $first = '2026_01_01_000001_create_log_probe_table';
        $directory = $this->migrationDirectory([
            $first => $this->migration(<<<'PHP'
Schema::create('log_probe', function (Blueprint $table): void {
    $table->id();
    $table->boolean('previous_was_logged');
});
PHP),
            '2026_01_01_000002_observe_previous_log' => $this->migration(<<<PHP
DB::table('log_probe')->insert([
    'previous_was_logged' => DB::table('migrations')
        ->where('migration', '{$first}')
        ->exists(),
]);
PHP),
        ]);

        $this->migrator()->run($directory);

        self::assertSame(1, DB::table('log_probe')->value('previous_was_logged'));
    }

    public function test_existing_table_guard_is_logged_but_does_not_replace_activation(): void
    {
        Schema::create('users', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
        });
        DB::table('migrations')->insert([
            'migration' => '2020_01_01_000000_create_users_table',
            'batch' => 1,
        ]);

        $baseline = '2026_01_01_000001_create_users_baseline';
        $directory = $this->migrationDirectory([
            $baseline => $this->migration(<<<'PHP'
if (Schema::hasTable('users')) {
    return;
}

Schema::create('users', function (Blueprint $table): void {
    $table->id();
});
PHP),
        ]);

        $this->migrator()->run($directory);

        self::assertSame([
            '2020_01_01_000000_create_users_table',
            $baseline,
        ], DB::table('migrations')->orderBy('id')->pluck('migration')->all());
    }

    public function test_irreversible_baseline_rollback_fails_without_dropping_schema_or_log(): void
    {
        $baseline = '2026_01_01_000001_create_accounts_baseline';
        $directory = $this->migrationDirectory([
            $baseline => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'MGF-ROLLBACK-001: Migrafold baselines are irreversible; restore archived history or rebuild explicitly.'
        );
    }
};
PHP,
        ]);

        $this->migrator()->run($directory);

        try {
            $this->migrator()->rollback($directory);
            self::fail('The irreversible baseline unexpectedly rolled back.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('MGF-ROLLBACK-001:', $exception->getMessage());
        }

        self::assertTrue(Schema::hasTable('accounts'));
        self::assertSame(1, DB::table('migrations')->where('migration', $baseline)->count());
    }

    private function migration(string $up): string
    {
        return <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
{$this->indent($up, 8)}
    }

    public function down(): void
    {
        // Lifecycle fixture; rollback behavior is tested separately.
    }
};
PHP;
    }

    private function indent(string $code, int $spaces): string
    {
        $prefix = str_repeat(' ', $spaces);

        return $prefix.str_replace("\n", "\n{$prefix}", trim($code));
    }
}
