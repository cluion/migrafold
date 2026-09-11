# Migrafold

Migrafold folds Laravel migration history into verified, deploy-safe baseline migrations.

> Status: pre-alpha feasibility work. No compaction or production mutation command is available yet.

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

The initial framework-lifecycle fixtures verify Laravel's pending-list, migration-log, existing-table guard, and fail-closed rollback behavior before public compaction APIs are introduced.

## Development

```bash
composer install
composer test
composer analyse
```

The tests use an in-memory SQLite database and never connect to an application database.
