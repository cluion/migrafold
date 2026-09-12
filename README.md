# Migrafold

Migrafold folds Laravel migration history into verified, deploy-safe baseline migrations.

> Status: pre-alpha. Planning and explicitly confirmed execution commands are available for controlled evaluation.

## Product boundary

Migrafold is designed to produce readable, per-table PHP baseline migrations while preserving safety around existing databases, migration records, archived source files, and Module ownership.

The planned first release targets:

- PHP 8.2 or newer.
- Laravel 12 and 13.
- SQLite, MySQL, and MariaDB with same-engine verification.
- Plain Laravel, Moduark, and nWidart Module discovery.
- Archive by default; explicit confirmation for deletion.
- Manifest-scoped migration-record activation instead of truncating the repository.

## Current development

The current implementation includes:

- Framework-lifecycle fixtures for Laravel's pending-list, migration-log, existing-table guard, and fail-closed rollback behavior.
- A read-only SQLite schema inspector for columns, defaults, collations, generated columns, indexes, and foreign keys.
- A read-only MySQL/MariaDB inspector with real-server coverage and fail-closed detection for schema details that are not yet representable.
- Canonical, deterministic JSON snapshots with SHA-256 fingerprints and an explicit capability report.
- A deterministic per-table PHP migration generator with dependency ordering, existing-table guards, and irreversible rollback protection.
- A dry-run-first output writer with collision refusal, verified file publication, and deterministic manifests.
- Deterministic migration discovery for Laravel applications, active Moduark Modules, and active nWidart Modules, including explicit table ownership and source fingerprints.
- Owner-aware output planning with per-owner manifests, global dry-run preflight, and rollback across application and Module directories.
- Manifest-protected source archival or explicit deletion with fingerprint revalidation and cross-owner rollback.
- Transactional, lock-protected migration-record activation that replaces only the exact retired scope and preserves unrelated history.
- An end-to-end `migrafold:plan` command with human-readable and JSON output, automatic Moduark runtime discovery, and no filesystem or migration-record writes.
- A recoverable execution coordinator that retains private source checkpoints until record activation commits and compensates filesystem changes on failure.
- An explicitly confirmed `migrafold:compact` command with whole-plan fingerprint binding and a second confirmation for permanent deletion.
- Catalog-wide AST classification that compacts schema-only migrations, preserves data-only migrations, and blocks mixed, raw, dynamic, unsupported, or invalid migrations.
- SQLite, MySQL, and MariaDB source/baseline replay in separate temporary databases, including current-database fingerprint verification and baseline-before-preserved ordering checks.
- Exact compacted scope propagation: preserved migrations remain in place and their migration records are not retired.
- Planner-generated v2 manifests that record the global compacted/preserved migration scope and same-engine replay fingerprints without database credentials or temporary sandbox identities.
- Fail-closed detection for schema features that cannot yet be represented safely.

## Preview a compaction

```bash
php artisan migrafold:plan \
    --date=2026_09_12 \
    --archive-id=2026-09-12T120000Z
```

Use `--json` for machine-readable output or `--delete` to preview permanent source deletion. The command replays source and generated migrations in task-specific temporary databases, then removes them. It does not change the selected database, move or delete source files, publish baselines, or activate migration records.

SQLite sandboxes are local temporary files. MySQL and MariaDB sandboxes are separate databases on the selected server, created with a fixed `migrafold_replay_` prefix and random identity. Cleanup requires the expected name, token, server identity, and database-resident ownership marker to match. The selected database account must be allowed to create and drop databases. Cross-database foreign keys, active source transactions, and non-empty table prefixes fail closed.

The plan output includes a fingerprint covering the schema, source fingerprints, migration classifications and actions, owner paths, generated outputs, source disposition, migration table, and record replacement scope. Data-only migrations are reported under `analysis.preserve`; their row data is replayed for execution safety but is not compared for equality.

Every application or Module directory receiving baselines also receives a `migrafold-manifest-v2` file. It records the globally verified compacted and preserved migration entries, their source fingerprints and owners, source/baseline/current schema fingerprints, migration counts, and the same-engine replay mode. Temporary database names, server addresses, and credentials are not persisted.

## Execute a compaction

Run the planning command first with an explicit date and archive identifier, review its complete scope, then pass the reported fingerprint to the execution command:

```bash
php artisan migrafold:compact \
    --date=2026_09_12 \
    --archive-id=2026-09-12T120000Z \
    --confirm=<plan-fingerprint>
```

Without `--confirm`, an interactive terminal asks for the exact fingerprint. Non-interactive execution requires the option. If the database schema, migration source contents, ownership, output paths, archive destination, or record scope changes after planning, the fingerprint no longer matches and execution stops without mutation.

Permanent deletion requires the same fingerprint twice:

```bash
php artisan migrafold:compact \
    --date=2026_09_12 \
    --delete \
    --confirm=<plan-fingerprint> \
    --confirm-delete=<plan-fingerprint>
```

Archive mode remains the default. The execution command has no general force bypass.

When all three Moduark runtime services are available, active Module migration directories and table ownership are included automatically. A partial Moduark runtime fails closed instead of silently producing an incomplete plan.

When `nwidart/laravel-modules` is active, Migrafold uses its repository's enabled Module list and configured migration generator path. Because nWidart does not expose authoritative table ownership, configure every Module table explicitly:

```php
// config/migrafold.php
return [
    'nwidart' => [
        'table_owners' => [
            'invoices' => 'Billing',
            'invoice_items' => 'Billing',
        ],
    ],
];
```

Publish the configuration with `php artisan vendor:publish --tag=migrafold-config`. An inactive or unknown owner, unsafe Module path, vendor-owned Module, unsafe generator path, or Module migration history without explicit ownership stops planning before any mutation.

## Schema generation support

Migrafold renders the inspected physical schema rather than trying to recover the original Laravel helper calls. The verified column mapping covers:

- Signed and unsigned integer families, booleans, and safe auto-increment primary keys.
- `char`, `varchar`, text families, and JSON.
- Date, datetime, timestamp, time, and year columns with available precision.
- Decimal, SQLite numeric, float, and double columns, including MySQL/MariaDB unsigned modifiers.
- Enum, set, blob, fixed binary, and variable binary columns.
- Nullable values, raw defaults, collations, comments, and virtual or stored generated expressions where the database exposes them.

Types or modifiers without a lossless Blueprint representation stop generation with `MGF-GENERATE-001`. Examples include bit fields, precision-bearing SQLite numeric declarations, ambiguous `real` columns, nonstandard blob sizes, and spatial columns. Existing inspector-level safety checks still reject schema features such as triggers, check constraints, expression indexes, and partial or prefix indexes before rendering.

## Development

```bash
composer install
composer test
composer test:consumer
composer analyse
composer test:databases
composer test:moduark
composer test:nwidart
```

The default tests use an in-memory SQLite database. Database integration tests start isolated MySQL 8.0 and MariaDB 11.8 containers, accept only dedicated `*_testing` databases, and remove their containers and storage after the run.

Consumer acceptance exports the current Git commit, installs a non-symlinked package copy into isolated Laravel 12 and 13 applications, and verifies automatic package discovery, planning, archival compaction, delete-mode confirmation refusal, exact migration-record activation, and fresh baseline replay. Development-only files and local uncommitted changes are excluded from the package under test.

The Moduark interoperability tests install isolated matching-major dependency sets for Laravel 12 and 13 with the current stable Moduark 1.x release. Each harness exercises the official registry, resource manifest, and table ownership runtime through baseline publication, source archival, and migration-record activation.

The nWidart interoperability tests install isolated matching-major dependency sets for Laravel 12 with nWidart 12 and Laravel 13 with nWidart 13. Each harness uses its own dedicated SQLite `*_testing` database and exercises official runtime discovery through baseline publication, source archival, and migration-record activation.

Continuous integration runs the core test and static-analysis suite against Laravel 12 on PHP 8.2 and Laravel 13 on PHP 8.3. Separate jobs run both interoperability harnesses across their matching Laravel majors. A path-filtered database workflow runs the same matching-major matrix against disposable MySQL 8.0 and MariaDB 11.8 containers.
