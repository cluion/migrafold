# Migrafold

Migrafold folds Laravel migration history into verified, deploy-safe baseline migrations.

> Status: pre-alpha feasibility work. A read-only planning command is available; no production mutation command is available yet.

## Product boundary

Migrafold is designed to produce readable, per-table PHP baseline migrations while preserving safety around existing databases, migration records, archived source files, and Module ownership.

The planned first release targets:

- PHP 8.2 or newer.
- Laravel 12 and 13.
- SQLite, MySQL, and MariaDB with same-engine verification.
- Plain Laravel and first-party Moduark discovery.
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
- Deterministic migration discovery for Laravel applications and active Moduark Modules, including explicit table ownership and source fingerprints.
- Owner-aware output planning with per-owner manifests, global dry-run preflight, and rollback across application and Module directories.
- Manifest-protected source archival or explicit deletion with fingerprint revalidation and cross-owner rollback.
- Transactional, lock-protected migration-record activation that replaces only the exact retired scope and preserves unrelated history.
- An end-to-end `migrafold:plan` command with human-readable and JSON output, automatic Moduark runtime discovery, and no filesystem or migration-record writes.
- A recoverable execution coordinator that retains private source checkpoints until record activation commits and compensates filesystem changes on failure.
- Fail-closed detection for schema features that cannot yet be represented safely.

## Preview a compaction

```bash
php artisan migrafold:plan \
    --date=2026_09_12 \
    --archive-id=2026-09-12T120000Z
```

Use `--json` for machine-readable output or `--delete` to preview permanent source deletion. The command only inspects the selected database connection and migration sources; it does not create, move, delete, or activate anything.

When all three Moduark runtime services are available, active Module migration directories and table ownership are included automatically. A partial Moduark runtime fails closed instead of silently producing an incomplete plan.

## Development

```bash
composer install
composer test
composer analyse
composer test:databases
```

The default tests use an in-memory SQLite database. Database integration tests start isolated MySQL 8.0 and MariaDB 11.8 containers, accept only dedicated `*_testing` databases, and remove their containers and storage after the run.
