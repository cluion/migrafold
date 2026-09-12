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
$manifest = $migrationDirectory.'/.migrafold-manifest.json';

$invoke = static function (string $command, array $arguments = []) use ($kernel): array {
    $status = $kernel->call($command, $arguments);
    $output = $kernel->output();

    return [$status, $output];
};

$call = static function (string $command, array $arguments = []) use ($invoke): string {
    [$status, $output] = $invoke($command, $arguments);

    if ($status !== 0) {
        throw new RuntimeException("Command [{$command}] failed with status {$status}: {$output}");
    }

    return $output;
};

$verifyInstalled = static function (
    string $baselinePath,
    int $expectedBatch,
) use ($call, $connection, $manifest): void {
    $baseline = pathinfo($baselinePath, PATHINFO_FILENAME);
    $paths = [$baselinePath, $manifest];
    $hashes = [];

    foreach ($paths as $path) {
        $hash = hash_file('sha256', $path);

        if (! is_string($hash)) {
            throw new RuntimeException("Unable to fingerprint verified consumer file [{$path}].");
        }

        $hashes[$path] = $hash;
    }

    $recordsBefore = array_map(
        static fn (object $record): array => (array) $record,
        $connection->table('migrations')->orderBy('id')->get(['id', 'migration', 'batch'])->all(),
    );
    $output = $call('migrafold:verify', [
        '--json' => true,
        '--no-interaction' => true,
    ]);
    $verification = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $schemaSummary = is_array($verification) ? ($verification['schema'] ?? null) : null;
    $manifestSummary = is_array($verification) ? ($verification['manifests'] ?? null) : null;
    $migrationSummary = is_array($verification) ? ($verification['migrations'] ?? null) : null;
    $recordSummary = is_array($verification) ? ($verification['records'] ?? null) : null;

    if (! is_array($verification)
        || ($verification['verified'] ?? null) !== true
        || ! is_array($schemaSummary)
        || ($schemaSummary['driver'] ?? null) !== 'sqlite'
        || ! is_string($schemaSummary['fingerprint'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/', $schemaSummary['fingerprint']) !== 1
        || $manifestSummary !== [[
            'owner' => 'laravel:application',
            'path' => $manifest,
        ]]
        || ! is_array($migrationSummary)
        || ($migrationSummary['compacted'] ?? null) !== ['2020_01_01_000000_create_users_table']
        || ($migrationSummary['preserved'] ?? null) !== []
        || ($migrationSummary['baselines'] ?? null) !== [$baseline]
        || ($migrationSummary['untracked'] ?? null) !== []
        || ! is_array($recordSummary)
        || ($recordSummary['table'] ?? null) !== 'migrations'
        || ($recordSummary['baseline_batch'] ?? null) !== $expectedBatch
        || ($recordSummary['preserved_recorded'] ?? null) !== []
        || ($recordSummary['preserved_pending'] ?? null) !== []) {
        throw new RuntimeException('Installed consumer verification did not report the expected exact scope.');
    }

    foreach ($hashes as $path => $hash) {
        if (hash_file('sha256', $path) !== $hash) {
            throw new RuntimeException("Consumer verification changed file [{$path}].");
        }
    }

    $recordsAfter = array_map(
        static fn (object $record): array => (array) $record,
        $connection->table('migrations')->orderBy('id')->get(['id', 'migration', 'batch'])->all(),
    );

    if ($recordsAfter !== $recordsBefore) {
        throw new RuntimeException('Consumer verification changed migration records.');
    }
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

$verifyInstalled($baselines[0], 2);

$schema->dropAllTables();
$call('migrate', ['--force' => true, '--no-interaction' => true]);

if (! $schema->hasTable('users')
    || ! $schema->hasColumns('users', ['id', 'email', 'nickname'])
    || $connection->table('migrations')->pluck('migration')->all() !== [$baseline]) {
    throw new RuntimeException('Installed baseline did not replay on a fresh consumer database.');
}

$verifyInstalled($baselines[0], 1);

$schema->dropAllTables();

if (! rename($archive, $source)
    || ! unlink($baselines[0])
    || ! unlink($manifest)
    || ! rmdir(dirname($archive))
    || ! rmdir(dirname(dirname($archive)))) {
    throw new RuntimeException('Unable to restore the consumer source fixture for delete acceptance.');
}

$call('migrate', ['--force' => true, '--no-interaction' => true]);
$deletePlanOutput = $call('migrafold:plan', [
    '--date' => '2026_09_12',
    '--delete' => true,
    '--json' => true,
    '--no-interaction' => true,
]);
$deletePlan = json_decode($deletePlanOutput, true, flags: JSON_THROW_ON_ERROR);
$deleteRecords = is_array($deletePlan) ? ($deletePlan['records'] ?? null) : null;
$deleteFingerprint = is_array($deletePlan) ? ($deletePlan['plan_fingerprint'] ?? null) : null;
$deleteDisposition = is_array($deletePlan) ? ($deletePlan['source_disposition'] ?? null) : null;

if (! is_array($deleteRecords)
    || $deleteDisposition !== 'delete'
    || ($deleteRecords['retire'] ?? null) !== ['2020_01_01_000000_create_users_table']
    || ! is_string($deleteFingerprint)) {
    throw new RuntimeException('Consumer delete plan did not contain the expected exact scope.');
}

$sourceHash = hash_file('sha256', $source);
$recordsBeforeRefusal = $connection->table('migrations')->orderBy('id')->pluck('migration')->all();

if (! is_string($sourceHash)) {
    throw new RuntimeException('Unable to fingerprint the delete-mode source migration.');
}

[$refusedStatus, $refusedOutput] = $invoke('migrafold:compact', [
    '--date' => '2026_09_12',
    '--delete' => true,
    '--confirm' => $deleteFingerprint,
    '--confirm-delete' => 'incorrect-fingerprint',
    '--no-interaction' => true,
]);
$refusedBaselines = glob($migrationDirectory.'/2026_09_12_*_baseline.php');

if ($refusedStatus !== 1
    || ! str_contains($refusedOutput, '--confirm-delete does not match the current plan fingerprint')
    || ! is_file($source)
    || hash_file('sha256', $source) !== $sourceHash
    || is_file($manifest)
    || $refusedBaselines === false
    || $refusedBaselines !== []
    || ! $schema->hasTable('users')
    || ! $schema->hasColumns('users', ['id', 'email', 'nickname'])
    || $connection->table('migrations')->orderBy('id')->pluck('migration')->all() !== $recordsBeforeRefusal) {
    throw new RuntimeException('Refused consumer deletion changed files or migration records.');
}

$call('migrafold:compact', [
    '--date' => '2026_09_12',
    '--delete' => true,
    '--confirm' => $deleteFingerprint,
    '--confirm-delete' => $deleteFingerprint,
    '--no-interaction' => true,
]);

$deleteBaselinePath = (static function (string $directory, string $source, string $manifest): string {
    clearstatcache();
    $baselines = glob($directory.'/2026_09_12_*_create_users_baseline.php');

    if ($baselines === false || count($baselines) !== 1) {
        throw new RuntimeException('Confirmed consumer deletion did not publish exactly one baseline.');
    }

    if (is_file($source) || ! is_file($manifest) || is_dir($directory.'/.migrafold-archive')) {
        throw new RuntimeException('Confirmed consumer deletion did not produce the expected filesystem state.');
    }

    return $baselines[0];
})($migrationDirectory, $source, $manifest);
$deleteBaseline = pathinfo($deleteBaselinePath, PATHINFO_FILENAME);

if ($connection->table('migrations')->orderBy('id')->pluck('migration')->all() !== [$deleteBaseline]) {
    throw new RuntimeException('Confirmed consumer deletion did not activate the exact baseline record scope.');
}

$verifyInstalled($deleteBaselinePath, 2);

$schema->dropAllTables();
$call('migrate', ['--force' => true, '--no-interaction' => true]);

if (! $schema->hasTable('users')
    || ! $schema->hasColumns('users', ['id', 'email', 'nickname'])
    || $connection->table('migrations')->pluck('migration')->all() !== [$deleteBaseline]) {
    throw new RuntimeException('Delete-mode baseline did not replay on a fresh consumer database.');
}

$verifyInstalled($deleteBaselinePath, 1);

fwrite(STDOUT, json_encode([
    'laravel' => Application::VERSION,
    'archive' => true,
    'activation' => true,
    'archive_verify' => true,
    'fresh_replay' => true,
    'archive_fresh_verify' => true,
    'delete_refusal_unchanged' => true,
    'delete' => true,
    'delete_verify' => true,
    'delete_fresh_replay' => true,
    'delete_fresh_verify' => true,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
