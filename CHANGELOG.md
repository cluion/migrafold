# Changelog

All notable changes to Migrafold are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Release dates are represented by annotated Git tags and GitHub releases.

## [Unreleased]

### Added

- PostgreSQL schema inspection with lossless Laravel Blueprint rendering for supported native types, defaults, indexes, foreign keys, and generated baseline migrations.
- Same-engine PostgreSQL replay verification in isolated databases with guarded ownership markers and deterministic cleanup.
- PostgreSQL migration-record activation using transaction-scoped advisory locks with commit, rollback, contention, and timeout coverage.

### Changed

- Extend real-database acceptance to PostgreSQL 16 and 17 across Laravel 12 and 13, including application, Moduark, and nWidart migration ownership.
- Exercise both archive and permanently confirmed deletion flows in PostgreSQL clean-package consumer acceptance.

### Safety

- Fail closed for unsupported PostgreSQL schemas, non-`public` search paths, cross-schema references, unavailable server identity, replay drift, and contended activation locks.

## [0.1.2] - 2026-09-12

### Fixed

- Generate native MariaDB UUID columns without misclassifying their physical representation during baseline rendering.

### Added

- Real MySQL and MariaDB clean-package consumer acceptance across Laravel 12 and 13 with 78 migrations distributed between the application, Moduark, and nWidart owners.
- End-to-end archive, migration-record activation, installed-state verification, and fresh baseline replay coverage against disposable database containers.

## [0.1.1]

### Fixed

- Generate lossless Laravel Blueprint definitions for MariaDB unsigned integer columns that expose their engine-default display widths.
- Cover unsigned tiny, small, medium, regular, and big integers with real MySQL and MariaDB schema round-trip regression tests.

## [0.1.0]

### Added

- Readable, deterministic per-table Laravel baseline migration generation from inspected physical schemas.
- `migrafold:plan`, `migrafold:compact`, and `migrafold:verify` commands for previewing, executing, and auditing a compaction.
- Laravel application migration discovery plus active Moduark and nWidart Module discovery.
- Explicit nWidart table ownership configuration and runtime-backed Moduark table ownership.
- Archive-by-default source handling with an explicitly confirmed permanent deletion mode.
- Exact migration-record replacement that preserves unrelated and data-only migration history.
- SQLite, MySQL, and MariaDB schema inspection and same-engine source-to-baseline replay.
- Owner-specific baseline files and deterministic v2 audit manifests.
- Clean installed-consumer, Moduark, nWidart, MySQL, and MariaDB acceptance harnesses across supported Laravel versions.

### Safety

- Refuse execution when the reviewed plan fingerprint no longer matches current inputs.
- Require a second matching fingerprint before permanent source deletion.
- Fail closed for mixed, raw, dynamic, invalid, or unsupported migration definitions and schema features.
- Preserve data-only migrations instead of folding them into schema baselines.
- Guard generated migrations so existing tables are skipped without mutating them.
- Keep recoverable source checkpoints until migration-record activation commits.
- Verify installed baselines, manifests, migration records, and current schema without mutating them.

[Unreleased]: https://github.com/cluion/migrafold/compare/v0.1.2...HEAD
[0.1.2]: https://github.com/cluion/migrafold/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/cluion/migrafold/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/cluion/migrafold/releases/tag/v0.1.0
