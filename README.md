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
- A dry-run-first output writer with collision refusal, verified file publication, and a deterministic source/output manifest.
- Deterministic migration discovery for Laravel applications, active Moduark Modules, and active nWidart Modules, including explicit table ownership and source fingerprints.
- Owner-aware output planning with per-owner manifests, global dry-run preflight, and rollback across application and Module directories.
- Manifest-protected source archival or explicit deletion with fingerprint revalidation and cross-owner rollback.
- Transactional, lock-protected migration-record activation that replaces only the exact retired scope and preserves unrelated history.
- An end-to-end `migrafold:plan` command with human-readable and JSON output, automatic Moduark runtime discovery, and no filesystem or migration-record writes.
- A recoverable execution coordinator that retains private source checkpoints until record activation commits and compensates filesystem changes on failure.
- An explicitly confirmed `migrafold:compact` command with whole-plan fingerprint binding and a second confirmation for permanent deletion.
- Fail-closed detection for schema features that cannot yet be represented safely.

## Preview a compaction

```bash
php artisan migrafold:plan \
    --date=2026_09_12 \
    --archive-id=2026-09-12T120000Z
```

Use `--json` for machine-readable output or `--delete` to preview permanent source deletion. The command only inspects the selected database connection and migration sources; it does not create, move, delete, or activate anything.

The plan output includes a fingerprint covering the schema, source fingerprints, owner paths, generated outputs, source disposition, migration table, and record replacement scope.

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

## Development

```bash
composer install
composer test
composer analyse
composer test:databases
composer test:nwidart
```

The default tests use an in-memory SQLite database. Database integration tests start isolated MySQL 8.0 and MariaDB 11.8 containers, accept only dedicated `*_testing` databases, and remove their containers and storage after the run.

The nWidart interoperability tests install isolated matching-major dependency sets for Laravel 12 with nWidart 12 and Laravel 13 with nWidart 13. Each harness uses its own dedicated SQLite `*_testing` database and exercises official runtime discovery through baseline publication, source archival, and migration-record activation.
