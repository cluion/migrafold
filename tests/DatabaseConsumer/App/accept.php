<?php

declare(strict_types=1);

use Cluion\Migrafold\Planning\SchemaInspectorResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
require __DIR__.'/vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/bootstrap/app.php';
$driver = getenv('DB_CONNECTION');
$database = getenv('DB_DATABASE');
$disposition = getenv('MIGRAFOLD_CONSUMER_DISPOSITION') ?: 'archive';

if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
    throw new RuntimeException('Database consumer acceptance requires mysql, mariadb, or pgsql.');
}

if (! is_string($database) || ! str_ends_with($database, '_testing')) {
    throw new RuntimeException('Database consumer acceptance requires a dedicated _testing database.');
}

if (! in_array($disposition, ['archive', 'delete'], true)) {
    throw new RuntimeException('Database consumer acceptance disposition must be archive or delete.');
}

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});
$databases = $app->make('db');

if (! $databases instanceof DatabaseManager) {
    throw new RuntimeException('Consumer Laravel database manager is unavailable.');
}

$connection = $databases->connection($driver);

if ($connection->getDriverName() !== $driver || $connection->getDatabaseName() !== $database) {
    throw new RuntimeException('Consumer database connection does not match the requested isolated target.');
}

$expectedServerMajor = getenv('MIGRAFOLD_EXPECTED_SERVER_MAJOR');
$serverVersion = $connection->getServerVersion();

if (is_string($expectedServerMajor)
    && $expectedServerMajor !== ''
    && ! str_starts_with($serverVersion, $expectedServerMajor.'.')) {
    throw new RuntimeException(
        "Consumer database server [{$serverVersion}] does not match expected major [{$expectedServerMajor}].",
    );
}

$migrationDirectories = [
    'laravel:application' => __DIR__.'/database/migrations',
    'moduark:Billing' => __DIR__.'/app/Modules/Billing/Database/Migrations',
    'nwidart:Inventory' => __DIR__.'/Modules/Inventory/database/migrations',
];
$expectedSourceCounts = [
    'laravel:application' => 46,
    'moduark:Billing' => 16,
    'nwidart:Inventory' => 16,
];
$expectedCompactedCounts = [
    'laravel:application' => 43,
    'moduark:Billing' => 15,
    'nwidart:Inventory' => 15,
];
$expectedBaselineCounts = [
    'laravel:application' => 18,
    'moduark:Billing' => 3,
    'nwidart:Inventory' => 3,
];
$expectedPreserved = [
    '2030_01_01_000001_seed_acceptance_alpha',
    '2030_01_01_000002_seed_acceptance_beta',
    '2030_01_01_000003_seed_acceptance_gamma',
    '2030_01_02_000001_seed_acceptance_invoice',
    '2030_01_03_000001_seed_acceptance_warehouse',
];

$migrationFiles = static function (string $directory): array {
    $files = glob($directory.'/*.php');

    if ($files === false) {
        throw new RuntimeException("Unable to inspect migration directory [{$directory}].");
    }

    sort($files, SORT_STRING);

    return $files;
};

foreach ($migrationDirectories as $owner => $directory) {
    if (count($migrationFiles($directory)) !== $expectedSourceCounts[$owner]) {
        throw new RuntimeException("Generated source count for [{$owner}] is not deterministic.");
    }
}

$invoke = static function (string $command, array $arguments = []) use ($kernel): array {
    $status = $kernel->call($command, $arguments);

    return [$status, $kernel->output()];
};

$call = static function (string $command, array $arguments = []) use ($invoke): string {
    [$status, $output] = $invoke($command, $arguments);

    if ($status !== 0) {
        throw new RuntimeException("Command [{$command}] failed with status {$status}: {$output}");
    }

    return $output;
};

$migrate = static function () use ($call, $migrationDirectories): void {
    $call('migrate', [
        '--path' => array_values($migrationDirectories),
        '--realpath' => true,
        '--force' => true,
        '--no-interaction' => true,
    ]);
};

$schemaFingerprint = static function (Connection $connection): string {
    return (new SchemaInspectorResolver())
        ->resolve($connection)
        ->inspect($connection, ['migrations'])
        ->fingerprint();
};

$dataSnapshot = static function (Connection $connection): array {
    return [
        'settings' => array_map(
            static fn (object $row): array => (array) $row,
            $connection->table('settings')
                ->where('key', 'like', 'acceptance.%')
                ->orderBy('key')
                ->get(['key', 'value'])
                ->all(),
        ),
        'invoices' => array_map(
            static fn (object $row): array => (array) $row,
            $connection->table('invoices')
                ->where('number', 'ACCEPTANCE-001')
                ->get(['number', 'status', 'total'])
                ->all(),
        ),
        'warehouses' => array_map(
            static fn (object $row): array => (array) $row,
            $connection->table('warehouses')
                ->where('code', 'ACC')
                ->get(['code', 'name'])
                ->all(),
        ),
    ];
};

$schema = $connection->getSchemaBuilder();
$schema->dropAllTables();
$migrate();

$sourceRecords = $connection->table('migrations')->pluck('migration')->all();

if (count($sourceRecords) !== 78 || count(array_unique($sourceRecords)) !== 78) {
    throw new RuntimeException('Original migration history did not execute exactly 78 unique files.');
}

$sourceFingerprint = $schemaFingerprint($connection);
$sourceData = $dataSnapshot($connection);

if (array_map('count', $sourceData) !== [
    'settings' => 3,
    'invoices' => 1,
    'warehouses' => 1,
]) {
    throw new RuntimeException('Preserved data migrations did not create the expected source data.');
}

$planArguments = [
    '--connection' => $driver,
    '--date' => '2026_09_12',
    '--json' => true,
    '--no-interaction' => true,
];

if ($disposition === 'delete') {
    $planArguments['--delete'] = true;
} else {
    $planArguments['--archive-id'] = 'database-consumer';
}

$planOutput = $call('migrafold:plan', $planArguments);
$plan = json_decode($planOutput, true, flags: JSON_THROW_ON_ERROR);
$planFingerprint = is_array($plan) ? ($plan['plan_fingerprint'] ?? null) : null;
$planSchema = is_array($plan) ? ($plan['schema'] ?? null) : null;
$planVerification = is_array($plan) ? ($plan['verification'] ?? null) : null;
$planRecords = is_array($plan) ? ($plan['records'] ?? null) : null;
$planOwners = is_array($plan) ? ($plan['owners'] ?? null) : null;

if (! is_string($planFingerprint)
    || preg_match('/\A[a-f0-9]{64}\z/', $planFingerprint) !== 1
    || ! is_array($planSchema)
    || ($planSchema['driver'] ?? null) !== $driver
    || ($planSchema['tables'] ?? null) !== 24
    || ($planSchema['fingerprint'] ?? null) !== $sourceFingerprint
    || ($plan['source_disposition'] ?? null) !== $disposition
    || ! is_array($planVerification)
    || ($planVerification['migration_counts'] ?? null) !== [
        'source' => 78,
        'baseline' => 24,
        'preserved' => 5,
    ]
    || ! is_array($planRecords)
    || count($planRecords['retire'] ?? []) !== 73
    || count($planRecords['activate'] ?? []) !== 24
    || ! is_array($planOwners)
    || count($planOwners) !== 3) {
    throw new RuntimeException('Compaction plan did not report the expected database consumer scope.');
}

$ownerSummary = [];

foreach ($planOwners as $owner) {
    if (! is_array($owner) || ! is_string($owner['id'] ?? null)) {
        throw new RuntimeException('Compaction plan returned an invalid owner summary.');
    }

    $ownerSummary[$owner['id']] = [
        'directory' => $owner['directory'] ?? null,
        'sources' => count($owner['sources'] ?? []),
        'baselines' => count($owner['baselines'] ?? []),
    ];
}

if (array_keys($ownerSummary) !== array_keys($migrationDirectories)) {
    throw new RuntimeException('Compaction plan did not preserve Laravel, Moduark, and nWidart ownership.');
}

foreach ($migrationDirectories as $owner => $directory) {
    if (($ownerSummary[$owner]['directory'] ?? null) !== $directory
        || ($ownerSummary[$owner]['sources'] ?? null) !== $expectedCompactedCounts[$owner]
        || ($ownerSummary[$owner]['baselines'] ?? null) !== $expectedBaselineCounts[$owner]) {
        throw new RuntimeException("Compaction plan reported an invalid owner scope for [{$owner}].");
    }
}

$compactArguments = [
    '--connection' => $driver,
    '--date' => '2026_09_12',
    '--confirm' => $planFingerprint,
    '--no-interaction' => true,
];

if ($disposition === 'delete') {
    $compactArguments['--delete'] = true;
    $compactArguments['--confirm-delete'] = $planFingerprint;
} else {
    $compactArguments['--archive-id'] = 'database-consumer';
}

$call('migrafold:compact', $compactArguments);

$activeMigrations = [];
$archivedMigrations = [];
$manifestOwners = [];

foreach ($migrationDirectories as $owner => $directory) {
    array_push($activeMigrations, ...$migrationFiles($directory));
    $archiveDirectory = $directory.'/.migrafold-archive/database-consumer';
    $archive = is_dir($archiveDirectory) ? $migrationFiles($archiveDirectory) : [];
    array_push($archivedMigrations, ...$archive);
    $manifestPath = $directory.'/.migrafold-manifest.json';
    $manifest = is_file($manifestPath)
        ? json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR)
        : null;

    if (! is_array($manifest)) {
        throw new RuntimeException("Owner [{$owner}] did not receive a readable manifest.");
    }

    $compacted = $manifest['migration_scope']['compacted'] ?? null;

    if (! is_array($compacted) || $compacted === []) {
        throw new RuntimeException("Owner [{$owner}] manifest has no compacted migrations.");
    }

    $manifestOwners[] = $manifest['owner']['id'] ?? null;
}

sort($manifestOwners, SORT_STRING);
$expectedOwners = array_keys($migrationDirectories);
sort($expectedOwners, SORT_STRING);

if (count($activeMigrations) !== 29
    || count($archivedMigrations) !== ($disposition === 'archive' ? 73 : 0)
    || $manifestOwners !== $expectedOwners) {
    throw new RuntimeException('Compaction file scope mismatch: '.json_encode([
        'active' => count($activeMigrations),
        'archived' => count($archivedMigrations),
        'manifest_owners' => $manifestOwners,
        'expected_owners' => $expectedOwners,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

$baselineFiles = array_values(array_filter(
    $activeMigrations,
    static fn (string $path): bool => str_contains(basename($path), '_baseline.php'),
));

if (count($baselineFiles) !== 24) {
    throw new RuntimeException('Compaction did not generate exactly one baseline per table.');
}

foreach ($baselineFiles as $baselineFile) {
    $contents = file_get_contents($baselineFile);

    if (! is_string($contents) || ! str_contains($contents, 'if (Schema::hasTable(')) {
        throw new RuntimeException("Generated baseline [{$baselineFile}] has no existing-table guard.");
    }
}

$activeNames = array_map(
    static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
    $activeMigrations,
);
$baselineNames = array_values(array_diff($activeNames, $expectedPreserved));
sort($baselineNames, SORT_STRING);
$activatedRecords = $connection->table('migrations')->pluck('migration')->all();
sort($activatedRecords, SORT_STRING);
$expectedActivatedRecords = [...$expectedPreserved, ...$baselineNames];
sort($expectedActivatedRecords, SORT_STRING);

if ($activatedRecords !== $expectedActivatedRecords
    || $schemaFingerprint($connection) !== $sourceFingerprint
    || $dataSnapshot($connection) !== $sourceData) {
    throw new RuntimeException('Compaction changed the live schema, preserved data, or migration record scope.');
}

$verifyOutput = $call('migrafold:verify', [
    '--connection' => $driver,
    '--json' => true,
    '--no-interaction' => true,
]);
$verification = json_decode($verifyOutput, true, flags: JSON_THROW_ON_ERROR);

if (! is_array($verification)
    || ($verification['verified'] ?? null) !== true
    || ($verification['schema']['driver'] ?? null) !== $driver
    || ($verification['schema']['fingerprint'] ?? null) !== $sourceFingerprint
    || count($verification['migrations']['compacted'] ?? []) !== 73
    || count($verification['migrations']['preserved'] ?? []) !== 5
    || count($verification['migrations']['baselines'] ?? []) !== 24
    || ($verification['migrations']['untracked'] ?? null) !== []) {
    throw new RuntimeException('Installed compaction verification did not report the exact expected scope.');
}

$schema->dropAllTables();
$migrate();
$freshRecords = $connection->table('migrations')->pluck('migration')->all();
sort($freshRecords, SORT_STRING);
$freshFingerprint = $schemaFingerprint($connection);
$freshData = $dataSnapshot($connection);

if ($freshRecords !== $expectedActivatedRecords
    || $freshFingerprint !== $sourceFingerprint
    || $freshData !== $sourceData) {
    throw new RuntimeException('Fresh baseline replay did not reproduce schema, data, and migration records.');
}

$freshVerifyOutput = $call('migrafold:verify', [
    '--connection' => $driver,
    '--json' => true,
    '--no-interaction' => true,
]);
$freshVerification = json_decode($freshVerifyOutput, true, flags: JSON_THROW_ON_ERROR);

if (! is_array($freshVerification)
    || ($freshVerification['verified'] ?? null) !== true
    || ($freshVerification['schema']['fingerprint'] ?? null) !== $sourceFingerprint) {
    throw new RuntimeException('Fresh database did not pass installed compaction verification.');
}

$schema->dropAllTables();

fwrite(STDOUT, json_encode([
    'laravel' => Application::VERSION,
    'driver' => $driver,
    'database' => $database,
    'server_version' => $serverVersion,
    'source_migrations' => 78,
    'compacted_migrations' => 73,
    'preserved_migrations' => 5,
    'tables' => 24,
    'baselines' => 24,
    'owners' => array_keys($migrationDirectories),
    'disposition' => $disposition,
    'activation' => true,
    'installed_verify' => true,
    'fresh_replay' => true,
    'fresh_verify' => true,
    'schema_fingerprint' => $sourceFingerprint,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
