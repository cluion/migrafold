# Changelog

All notable changes to Migrafold are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Release dates are represented by annotated Git tags and GitHub releases.

## [Unreleased]

### Added

- Classify literal PostgreSQL `VALIDATE CONSTRAINT`, `DROP CONSTRAINT`, and `RENAME CONSTRAINT` operations and verify ordered replacement lifecycles against the final replay snapshot.

## [0.3.0] - 2026-09-27

### Added

- Inspect and render validated, table-local PostgreSQL CHECK constraints as canonical, named baseline operations while keeping raw-SQL source migrations blocked.
- Inspect and render single-key PostgreSQL btree expression indexes, including unique and quoted names, while rejecting externally dependent variants.
- Inspect and render multi-key PostgreSQL btree expression indexes, including mixed column/expression keys and unique indexes, without changing existing single-key snapshot output.
- Inspect and render column-key PostgreSQL btree partial indexes with canonical predicates, including multi-column unique indexes, while rejecting partial expression indexes and custom dependencies.
- Inspect and render single-column PostgreSQL GIN indexes with default operator classes, while rejecting partial, expression, multi-column, and tuned-storage variants.
- Inspect and render PostgreSQL stored generated `tsvector` columns with native Laravel Blueprint syntax and catalog dependency validation.
- Inspect and render validated PostgreSQL deferrable foreign keys while preserving `INITIALLY IMMEDIATE` and `INITIALLY DEFERRED` semantics.
- Classify only single-statement, literal PostgreSQL `ADD ... CHECK` and `CREATE INDEX` effects, and require every accepted named object to exist in the source replay snapshot before compaction.

### Fixed

- Keep migration analysis compatible with PHP-Parser 5.9 argument placeholders while continuing to reject non-literal schema arguments.
- Declare Moduark `^1.3` as the supported optional integration range and report a missing `ResourceManifest` service explicitly.
- Recognize Laravel's `constrained()` foreign-key definition as a schema-only migration operation.
- Canonicalize PostgreSQL `ANY(ARRAY[...])` casts and associative boolean grouping so equivalent CHECK constraints and partial-index predicates keep identical fingerprints after replay.

## [0.2.0] - 2026-09-13

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

[Unreleased]: https://github.com/cluion/migrafold/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/cluion/migrafold/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/cluion/migrafold/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/cluion/migrafold/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/cluion/migrafold/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/cluion/migrafold/releases/tag/v0.1.0
