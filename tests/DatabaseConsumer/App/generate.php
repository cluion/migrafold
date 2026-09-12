<?php

declare(strict_types=1);

$root = __DIR__;
$applicationMigrations = $root.'/database/migrations';
$moduarkRoot = $root.'/app/Modules/Billing';
$moduarkMigrations = $moduarkRoot.'/Database/Migrations';
$nwidartRoot = $root.'/Modules/Inventory';
$nwidartMigrations = $nwidartRoot.'/database/migrations';

/** @param non-empty-string $directory */
$removeDirectory = static function (string $directory) use (&$removeDirectory): void {
    if (! is_dir($directory) || is_link($directory)) {
        return;
    }

    $entries = scandir($directory);

    if ($entries === false) {
        throw new RuntimeException("Unable to inspect fixture directory [{$directory}].");
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_dir($path) && ! is_link($path)) {
            $removeDirectory($path);
        } elseif (! unlink($path)) {
            throw new RuntimeException("Unable to remove fixture file [{$path}].");
        }
    }

    if (! rmdir($directory)) {
        throw new RuntimeException("Unable to remove fixture directory [{$directory}].");
    }
};

foreach ([$applicationMigrations, $moduarkRoot, $nwidartRoot] as $directory) {
    $removeDirectory($directory);
}

foreach ([$root.'/modules_statuses.json', $root.'/moduark-modules.json'] as $stateFile) {
    if (is_file($stateFile) && ! unlink($stateFile)) {
        throw new RuntimeException("Unable to reset fixture state file [{$stateFile}].");
    }
}

$write = static function (string $path, string $contents): void {
    $directory = dirname($path);

    if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
        throw new RuntimeException("Unable to create fixture directory [{$directory}].");
    }

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Unable to write fixture file [{$path}].");
    }
};

$indent = static fn (string $body): string => implode("\n", array_map(
    static fn (string $line): string => $line === '' ? '' : '        '.$line,
    explode("\n", trim($body)),
));

$migration = static function (
    string $directory,
    string $name,
    string $up,
    string $down,
) use ($indent, $write): void {
    $up = $indent($up);
    $down = $indent($down);
    $write($directory.'/'.$name.'.php', <<<PHP
<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
{$up}
    }

    public function down(): void
    {
{$down}
    }
};
PHP
    );
};

$create = static function (
    string $directory,
    string $name,
    string $table,
    string $definition,
) use ($migration): void {
    $migration(
        $directory,
        $name,
        "Schema::create('{$table}', static function (Blueprint \$table): void {\n".
            implode("\n", array_map(
                static fn (string $line): string => '    '.$line,
                explode("\n", trim($definition)),
            )).
            "\n});",
        "Schema::dropIfExists('{$table}');",
    );
};

$alter = static function (
    string $directory,
    string $name,
    string $table,
    string $up,
    string $down,
) use ($migration): void {
    $body = static fn (string $statements): string => "Schema::table('{$table}', static function (Blueprint \$table): void {\n".
        implode("\n", array_map(
            static fn (string $line): string => '    '.$line,
            explode("\n", trim($statements)),
        )).
        "\n});";

    $migration($directory, $name, $body($up), $body($down));
};

// These are the three migrations created by a fresh Laravel application. The
// jobs migration intentionally includes unsigned tiny/small/integer columns.
$migration($applicationMigrations, '0001_01_01_000000_create_users_table', <<<'PHP'
Schema::create('users', static function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->rememberToken();
    $table->timestamps();
});

Schema::create('password_reset_tokens', static function (Blueprint $table): void {
    $table->string('email')->primary();
    $table->string('token');
    $table->timestamp('created_at')->nullable();
});

Schema::create('sessions', static function (Blueprint $table): void {
    $table->string('id')->primary();
    $table->foreignId('user_id')->nullable()->index();
    $table->string('ip_address', 45)->nullable();
    $table->text('user_agent')->nullable();
    $table->longText('payload');
    $table->integer('last_activity')->index();
});
PHP,
<<<'PHP'
Schema::dropIfExists('sessions');
Schema::dropIfExists('password_reset_tokens');
Schema::dropIfExists('users');
PHP);

$migration($applicationMigrations, '0001_01_01_000001_create_cache_table', <<<'PHP'
Schema::create('cache', static function (Blueprint $table): void {
    $table->string('key')->primary();
    $table->mediumText('value');
    $table->integer('expiration');
});

Schema::create('cache_locks', static function (Blueprint $table): void {
    $table->string('key')->primary();
    $table->string('owner');
    $table->integer('expiration');
});
PHP,
<<<'PHP'
Schema::dropIfExists('cache_locks');
Schema::dropIfExists('cache');
PHP);

$migration($applicationMigrations, '0001_01_01_000002_create_jobs_table', <<<'PHP'
Schema::create('jobs', static function (Blueprint $table): void {
    $table->id();
    $table->string('queue')->index();
    $table->longText('payload');
    $table->unsignedTinyInteger('attempts');
    $table->unsignedInteger('reserved_at')->nullable();
    $table->unsignedInteger('available_at');
    $table->unsignedInteger('created_at');
});

Schema::create('job_batches', static function (Blueprint $table): void {
    $table->string('id')->primary();
    $table->string('name');
    $table->integer('total_jobs');
    $table->integer('pending_jobs');
    $table->integer('failed_jobs');
    $table->longText('failed_job_ids');
    $table->mediumText('options')->nullable();
    $table->integer('cancelled_at')->nullable();
    $table->integer('created_at');
    $table->integer('finished_at')->nullable();
});

Schema::create('failed_jobs', static function (Blueprint $table): void {
    $table->id();
    $table->string('uuid')->unique();
    $table->text('connection');
    $table->text('queue');
    $table->longText('payload');
    $table->longText('exception');
    $table->timestamp('failed_at')->useCurrent();
});
PHP,
<<<'PHP'
Schema::dropIfExists('failed_jobs');
Schema::dropIfExists('job_batches');
Schema::dropIfExists('jobs');
PHP);

$applicationTables = [
    'roles' => <<<'PHP'
$table->id();
$table->string('name')->unique();
$table->text('permissions')->nullable();
$table->timestamps();
PHP,
    'posts' => <<<'PHP'
$table->id();
$table->foreignId('user_id')->nullable();
$table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
$table->string('title', 80);
$table->text('body')->nullable();
$table->timestamps();
PHP,
    'comments' => <<<'PHP'
$table->id();
$table->foreignId('post_id');
$table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
$table->foreignId('user_id')->nullable();
$table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
$table->text('body');
$table->timestamps();
PHP,
    'tags' => <<<'PHP'
$table->id();
$table->string('name')->unique();
$table->timestamps();
PHP,
    'post_tag' => <<<'PHP'
$table->foreignId('post_id');
$table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
$table->foreignId('tag_id');
$table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
$table->primary(['post_id', 'tag_id']);
PHP,
    'profiles' => <<<'PHP'
$table->id();
$table->foreignId('user_id')->unique();
$table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
$table->string('display_name_tmp')->nullable();
$table->text('bio')->nullable();
$table->timestamps();
PHP,
    'settings' => <<<'PHP'
$table->id();
$table->string('key')->unique();
$table->text('value')->nullable();
PHP,
    'audits' => <<<'PHP'
$table->id();
$table->foreignId('user_id')->nullable();
$table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
$table->string('action');
$table->string('obsolete')->nullable();
$table->timestamp('created_at')->nullable();
PHP,
    'notifications' => <<<'PHP'
$table->uuid('id')->primary();
$table->foreignId('user_id')->nullable();
$table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
$table->string('type');
$table->text('data');
$table->timestamp('read_at')->nullable();
$table->timestamps();
PHP,
    'api_tokens' => <<<'PHP'
$table->id();
$table->foreignId('user_id');
$table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
$table->char('token', 64)->unique();
$table->timestamp('last_used_at')->nullable();
$table->timestamps();
PHP,
];

$sequence = 1;

foreach ($applicationTables as $table => $definition) {
    $create(
        $applicationMigrations,
        sprintf('2020_01_01_%06d_create_%s_table', $sequence++, $table),
        $table,
        $definition,
    );
}

$create($moduarkMigrations, '2020_01_02_000001_create_invoices_table', 'invoices', <<<'PHP'
$table->id();
$table->foreignId('user_id')->nullable();
$table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
$table->string('number')->unique();
$table->string('status')->default('draft');
$table->decimal('total', 12, 2)->default(0);
$table->timestamps();
PHP);
$create($moduarkMigrations, '2020_01_02_000002_create_invoice_items_table', 'invoice_items', <<<'PHP'
$table->id();
$table->foreignId('invoice_id');
$table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
$table->string('description');
$table->unsignedInteger('quantity');
$table->decimal('unit_price', 12, 2);
$table->timestamps();
PHP);
$create($moduarkMigrations, '2020_01_02_000003_create_payments_table', 'payments', <<<'PHP'
$table->id();
$table->foreignId('invoice_id');
$table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
$table->string('reference')->unique();
$table->decimal('amount', 12, 2);
$table->string('status')->default('pending');
$table->timestamps();
PHP);

$create($nwidartMigrations, '2020_01_03_000001_create_warehouses_table', 'warehouses', <<<'PHP'
$table->id();
$table->string('code')->unique();
$table->string('name');
$table->timestamps();
PHP);
$create($nwidartMigrations, '2020_01_03_000002_create_products_table', 'products', <<<'PHP'
$table->id();
$table->string('sku_draft')->unique();
$table->string('name');
$table->decimal('price', 12, 2);
$table->unsignedInteger('stock')->default(0);
$table->timestamps();
PHP);
$create($nwidartMigrations, '2020_01_03_000003_create_stock_movements_table', 'stock_movements', <<<'PHP'
$table->id();
$table->foreignId('product_id');
$table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
$table->foreignId('warehouse_id');
$table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
$table->integer('delta');
$table->string('reason')->nullable();
$table->timestamps();
PHP);

$specialApplicationChanges = [
    ['profiles', "\$table->renameColumn('display_name_tmp', 'display_name');", "\$table->renameColumn('display_name', 'display_name_tmp');"],
    ['posts', "\$table->string('title', 160)->change();", "\$table->string('title', 80)->change();"],
    ['audits', "\$table->dropColumn('obsolete');", "\$table->string('obsolete')->nullable();"],
    ['posts', "\$table->timestamp('published_at')->nullable()->index();", "\$table->dropIndex(['published_at']);\n\$table->dropColumn('published_at');"],
    ['roles', "\$table->unsignedSmallInteger('rank')->default(0);", "\$table->dropColumn('rank');"],
    ['settings', "\$table->text('payload')->nullable();", "\$table->dropColumn('payload');"],
];

$sequence = 1;

foreach ($specialApplicationChanges as [$table, $up, $down]) {
    $alter(
        $applicationMigrations,
        sprintf('2021_01_01_%06d_update_%s_%02d', $sequence, $table, $sequence),
        $table,
        $up,
        $down,
    );
    $sequence++;
}

$applicationNames = array_keys($applicationTables);

for ($index = 1; $index <= 24; $index++) {
    $table = $applicationNames[($index - 1) % count($applicationNames)];
    $column = sprintf('acceptance_%02d', $index);
    $alter(
        $applicationMigrations,
        sprintf('2021_01_01_%06d_add_%s_to_%s', $sequence++, $column, $table),
        $table,
        "\$table->string('{$column}')->nullable();",
        "\$table->dropColumn('{$column}');",
    );
}

$moduleChanges = [
    [$moduarkMigrations, '2021_01_02', 'invoices', "\$table->unsignedInteger('retry_count')->default(0);", "\$table->dropColumn('retry_count');"],
    [$moduarkMigrations, '2021_01_02', 'payments', "\$table->timestamp('processed_at')->nullable()->index();", "\$table->dropIndex(['processed_at']);\n\$table->dropColumn('processed_at');"],
    [$moduarkMigrations, '2021_01_02', 'invoice_items', "\$table->text('notes')->nullable();", "\$table->dropColumn('notes');"],
    [$nwidartMigrations, '2021_01_03', 'products', "\$table->renameColumn('sku_draft', 'sku');", "\$table->renameColumn('sku', 'sku_draft');"],
    [$nwidartMigrations, '2021_01_03', 'stock_movements', "\$table->unsignedSmallInteger('source_code')->default(0);", "\$table->dropColumn('source_code');"],
    [$nwidartMigrations, '2021_01_03', 'warehouses', "\$table->string('timezone')->default('UTC');", "\$table->dropColumn('timezone');"],
];
$moduleSequences = ['2021_01_02' => 1, '2021_01_03' => 1];

foreach ($moduleChanges as [$directory, $date, $table, $up, $down]) {
    $number = $moduleSequences[$date]++;
    $alter(
        $directory,
        sprintf('%s_%06d_update_%s_%02d', $date, $number, $table, $number),
        $table,
        $up,
        $down,
    );
}

foreach ([
    [$moduarkMigrations, '2021_01_02', ['invoices', 'invoice_items', 'payments'], 'billing'],
    [$nwidartMigrations, '2021_01_03', ['warehouses', 'products', 'stock_movements'], 'inventory'],
] as [$directory, $date, $tables, $prefix]) {
    for ($index = 1; $index <= 9; $index++) {
        $table = $tables[($index - 1) % count($tables)];
        $column = sprintf('%s_acceptance_%02d', $prefix, $index);
        $number = $moduleSequences[$date]++;
        $alter(
            $directory,
            sprintf('%s_%06d_add_%s_to_%s', $date, $number, $column, $table),
            $table,
            "\$table->string('{$column}')->nullable();",
            "\$table->dropColumn('{$column}');",
        );
    }
}

foreach ([
    ['2030_01_01_000001_seed_acceptance_alpha', 'acceptance.alpha', 'one'],
    ['2030_01_01_000002_seed_acceptance_beta', 'acceptance.beta', 'two'],
    ['2030_01_01_000003_seed_acceptance_gamma', 'acceptance.gamma', 'three'],
] as [$name, $key, $value]) {
    $migration(
        $applicationMigrations,
        $name,
        "DB::table('settings')->insert(['key' => '{$key}', 'value' => '{$value}']);",
        "DB::table('settings')->where('key', '{$key}')->delete();",
    );
}

$migration(
    $moduarkMigrations,
    '2030_01_02_000001_seed_acceptance_invoice',
    "DB::table('invoices')->insert(['number' => 'ACCEPTANCE-001', 'status' => 'draft', 'total' => 0]);",
    "DB::table('invoices')->where('number', 'ACCEPTANCE-001')->delete();",
);
$migration(
    $nwidartMigrations,
    '2030_01_03_000001_seed_acceptance_warehouse',
    "DB::table('warehouses')->insert(['code' => 'ACC', 'name' => 'Acceptance']);",
    "DB::table('warehouses')->where('code', 'ACC')->delete();",
);

$write($moduarkRoot.'/BillingModule.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use Cluion\Moduark\Module;

final class BillingModule extends Module
{
    /** @return list<string> */
    public function tables(): array
    {
        return ['invoice_items', 'invoices', 'payments'];
    }
}
PHP);

$write($nwidartRoot.'/module.json', json_encode([
    'name' => 'Inventory',
    'alias' => 'inventory',
    'description' => 'Migrafold real database consumer fixture',
    'keywords' => [],
    'priority' => 0,
    'order' => 0,
    'providers' => [],
    'aliases' => [],
    'files' => [],
    'requires' => [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
$write($root.'/modules_statuses.json', json_encode([
    'Inventory' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

$counts = [];

foreach ([
    'laravel:application' => $applicationMigrations,
    'moduark:Billing' => $moduarkMigrations,
    'nwidart:Inventory' => $nwidartMigrations,
] as $owner => $directory) {
    $files = glob($directory.'/*.php');

    if ($files === false) {
        throw new RuntimeException("Unable to count generated migrations for [{$owner}].");
    }

    $counts[$owner] = count($files);
}

if ($counts !== [
    'laravel:application' => 46,
    'moduark:Billing' => 16,
    'nwidart:Inventory' => 16,
]) {
    throw new RuntimeException('Generated migration counts are not deterministic: '.json_encode($counts));
}

fwrite(STDOUT, json_encode([
    'generated' => array_sum($counts),
    'schema' => 73,
    'data_only' => 5,
    'owners' => $counts,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
