<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;

require __DIR__.'/vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/bootstrap/app.php';
$database = getenv('DB_DATABASE');

if (! is_string($database) || ! str_ends_with($database, '_testing')) {
    throw new RuntimeException('Consumer acceptance requires a dedicated _testing database.');
}

$kernel = $app->make(Kernel::class);

$kernel->bootstrap();
$databases = $app->make('db');

if (! $databases instanceof DatabaseManager) {
    throw new RuntimeException('Consumer Laravel database manager is unavailable.');
}

$connection = $databases->connection();
$schema = $connection->getSchemaBuilder();
$migrationDirectory = __DIR__.'/database/migrations';
$source = $migrationDirectory.'/2020_01_01_000000_create_users_table.php';
$archive = $migrationDirectory.'/.migrafold-archive/consumer-acceptance/'.basename($source);

$call = static function (string $command, array $arguments = []) use ($kernel): string {
    $status = $kernel->call($command, $arguments);
    $output = $kernel->output();

    if ($status !== 0) {
        throw new RuntimeException("Command [{$command}] failed with status {$status}: {$output}");
    }

    return $output;
};

$call('migrate', ['--force' => true, '--no-interaction' => true]);

if (! $schema->hasTable('users')) {
    throw new RuntimeException('Source migration did not create the users table.');
}

$planOutput = $call('migrafold:plan', [
    '--date' => '2026_09_12',
    '--archive-id' => 'consumer-acceptance',
    '--json' => true,
    '--no-interaction' => true,
]);
$plan = json_decode($planOutput, true, flags: JSON_THROW_ON_ERROR);
$planSchema = is_array($plan) ? ($plan['schema'] ?? null) : null;
$planRecords = is_array($plan) ? ($plan['records'] ?? null) : null;
$fingerprint = is_array($plan) ? ($plan['plan_fingerprint'] ?? null) : null;
$sourceDisposition = is_array($plan) ? ($plan['source_disposition'] ?? null) : null;

if (! is_array($planSchema)
    || ! is_array($planRecords)
    || ($planSchema['tables'] ?? null) !== 1
    || $sourceDisposition !== 'archive'
    || ($planRecords['retire'] ?? null) !== ['2020_01_01_000000_create_users_table']
    || ! is_string($fingerprint)) {
    throw new RuntimeException('Consumer plan did not contain the expected exact scope.');
}

$call('migrafold:compact', [
    '--date' => '2026_09_12',
    '--archive-id' => 'consumer-acceptance',
    '--confirm' => $fingerprint,
    '--no-interaction' => true,
]);

$baselines = glob($migrationDirectory.'/2026_09_12_*_create_users_baseline.php');

if ($baselines === false || count($baselines) !== 1 || ! is_file($archive) || is_file($source)) {
    throw new RuntimeException('Consumer compaction did not publish and archive the expected files.');
}

$baseline = pathinfo($baselines[0], PATHINFO_FILENAME);
$records = $connection->table('migrations')->orderBy('id')->pluck('migration')->all();

if ($records !== [$baseline]) {
    throw new RuntimeException('Consumer compaction did not activate the exact baseline record scope.');
}

$schema->dropAllTables();
$call('migrate', ['--force' => true, '--no-interaction' => true]);

if (! $schema->hasTable('users')
    || ! $schema->hasColumns('users', ['id', 'email', 'nickname'])
    || $connection->table('migrations')->pluck('migration')->all() !== [$baseline]) {
    throw new RuntimeException('Installed baseline did not replay on a fresh consumer database.');
}

fwrite(STDOUT, json_encode([
    'laravel' => Application::VERSION,
    'baseline' => $baseline,
    'archive' => true,
    'activation' => true,
    'fresh_replay' => true,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
