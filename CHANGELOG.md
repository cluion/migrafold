# Changelog

All notable changes to Migrafold are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Release dates are represented by annotated Git tags and GitHub releases.

## [Unreleased]

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

[Unreleased]: https://github.com/cluion/migrafold/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/cluion/migrafold/releases/tag/v0.1.0
