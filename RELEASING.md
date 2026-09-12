# Releasing Migrafold

This checklist defines the evidence required for a Migrafold release. Complete it against one exact commit. Do not combine results from different commits, and do not publish from a dirty working tree.

Migrafold uses tag-driven releases. The annotated Git tag is the package version's single source of truth, and `composer.json` must not contain a `version` field. A dedicated version-bump or release commit is not required.

## 1. Prepare the candidate

- Confirm `git status --short` is empty.
- Confirm the intended commit with `git rev-parse HEAD`.
- Confirm `CHANGELOG.md` already contains the target version and accurate release notes.
- Confirm the README support matrix and release policy are accurate.
- If documentation needs correction, commit the substantive documentation change and repeat acceptance against the new commit. Do not create a commit solely to duplicate the version stored in the tag.

## 2. Validate package metadata and source

```bash
composer validate --strict
composer audit --locked --no-interaction
composer test
composer analyse
```

- Run `git diff --check` and inspect the complete candidate diff.
- Confirm public tracked files do not contain internal planning terminology or private paths.
- Confirm `.internal/`, dependencies, tests, and repository metadata are absent from `git archive HEAD`.
- Confirm the archive contains `LICENSE`, `README.md`, `CHANGELOG.md`, `composer.json`, configuration, and all runtime source files.

## 3. Run compatibility acceptance

Run every harness against the same candidate commit:

```bash
composer test:consumer
composer test:moduark
composer test:nwidart
composer test:databases
```

- Verify the core test and static-analysis suite with the Laravel 12 dependency set.
- Repeat it with the Laravel 13 dependency set.
- Audit the locked dependencies in both Laravel versions of each interoperability harness.
- Confirm database test containers, networks, volumes, and temporary replay databases are removed after the tests.

The clean-consumer run must install an exported package rather than a symlink or the development checkout. It must cover archive and deletion flows, migration-record activation, fresh baseline replay, and installed-state verification.

## 4. Verify hosted CI

- Push the candidate commit only after local acceptance succeeds.
- Require every core, interoperability, clean-consumer, and database job for that exact commit to pass.
- Record the accepted commit SHA before creating the tag.

Local results do not substitute for hosted CI, and a green hosted workflow does not by itself mean the package has been published.

## 5. Publish

- Create an annotated `vX.Y.Z` tag directly on the accepted commit without changing tracked files.
- Push the tag without rewriting existing history.
- Create a GitHub release from the matching changelog entry.
- Confirm Packagist has indexed the exact tag and commit.
- Install the tagged package in a fresh Laravel application and run package discovery.

The release is complete only after the tag, GitHub release, Packagist version, and fresh installation all resolve to the same commit.
