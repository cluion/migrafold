<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Verification;

use Cluion\Migrafold\Discovery\MigrationOwner;
use Cluion\Migrafold\Output\BaselineOutputPlan;
use Cluion\Migrafold\Output\SourceMigration;
use Cluion\Migrafold\Verification\Exception\ManifestVerificationFailed;
use JsonException;
use Throwable;

final readonly class CompactionManifestReader
{
    private const MAX_BYTES = 1_048_576;

    public function read(string $projectRoot, MigrationOwner $owner): ?InstalledCompactionManifest
    {
        $root = realpath($projectRoot);

        if ($root === false || ! is_dir($root)) {
            throw ManifestVerificationFailed::because(
                "project root [{$projectRoot}] is not a readable directory.",
            );
        }

        if (! $this->within($root, $owner->migrationDirectory)) {
            throw ManifestVerificationFailed::because(
                "migration directory for [{$owner->id}] is outside the project root.",
            );
        }

        if (! file_exists($owner->migrationDirectory) && ! is_link($owner->migrationDirectory)) {
            return null;
        }

        if (is_link($owner->migrationDirectory)) {
            throw ManifestVerificationFailed::because(
                "migration directory for [{$owner->id}] must not be a symbolic link.",
            );
        }

        $directory = realpath($owner->migrationDirectory);

        if ($directory === false || ! is_dir($directory) || ! $this->within($root, $directory)) {
            throw ManifestVerificationFailed::because(
                "migration directory for [{$owner->id}] is unreadable or outside the project root.",
            );
        }

        $path = $directory.DIRECTORY_SEPARATOR.BaselineOutputPlan::MANIFEST_FILENAME;

        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }

        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            throw ManifestVerificationFailed::because(
                "manifest for [{$owner->id}] is missing, unreadable, or a symbolic link.",
            );
        }

        $size = filesize($path);

        if (! is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            throw ManifestVerificationFailed::because(
                "manifest for [{$owner->id}] has an unsafe file size.",
            );
        }

        $contents = file_get_contents($path);

        if (! is_string($contents) || strlen($contents) !== $size) {
            throw ManifestVerificationFailed::because(
                "manifest for [{$owner->id}] could not be read completely.",
            );
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            $payload = $this->associative($decoded, "manifest for [{$owner->id}]");

            return $this->hydrate($payload, $path, $directory, $owner);
        } catch (ManifestVerificationFailed $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            throw ManifestVerificationFailed::because(
                "manifest for [{$owner->id}] is not valid JSON: {$exception->getMessage()}",
            );
        } catch (Throwable $exception) {
            throw ManifestVerificationFailed::because(
                "manifest for [{$owner->id}] contains invalid metadata: {$exception->getMessage()}",
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function hydrate(
        array $payload,
        string $path,
        string $directory,
        MigrationOwner $owner,
    ): InstalledCompactionManifest {
        if ($this->string($payload, 'format_version') !== 'migrafold-manifest-v2') {
            throw ManifestVerificationFailed::because(
                "manifest for [{$owner->id}] is not migrafold-manifest-v2.",
            );
        }

        $manifestOwner = $this->object($payload, 'owner');

        if ($this->string($manifestOwner, 'id') !== $owner->id
            || $this->string($manifestOwner, 'name') !== $owner->name) {
            throw ManifestVerificationFailed::because(
                "manifest owner does not match discovered owner [{$owner->id}].",
            );
        }

        $schema = $this->object($payload, 'schema');
        $schemaFormat = $this->string($schema, 'format_version');
        $driver = $this->string($schema, 'driver');
        $schemaFingerprint = $this->fingerprint($schema, 'sha256');
        $verification = $this->object($payload, 'verification');

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)
            || $this->string($verification, 'mode') !== 'same-engine-dual-sandbox'
            || $this->string($verification, 'driver') !== $driver
            || $this->boolean($verification, 'data_state_compared') !== false) {
            throw ManifestVerificationFailed::because(
                "manifest for [{$owner->id}] has inconsistent replay verification metadata.",
            );
        }

        $fingerprints = $this->object($verification, 'schema_fingerprints');

        foreach (['source_replay', 'baseline_replay', 'current_database'] as $key) {
            if ($this->fingerprint($fingerprints, $key) !== $schemaFingerprint) {
                throw ManifestVerificationFailed::because(
                    "manifest for [{$owner->id}] contains different schema fingerprints.",
                );
            }
        }

        $counts = $this->object($verification, 'migration_counts');
        $sourceCount = $this->nonNegativeInteger($counts, 'source');
        $baselineCount = $this->nonNegativeInteger($counts, 'baseline');
        $preservedCount = $this->nonNegativeInteger($counts, 'preserved');
        $scope = $this->object($payload, 'migration_scope');
        $compacted = $this->migrations($this->list($scope, 'compacted'), 'compact');
        $preserved = $this->migrations($this->list($scope, 'preserved'), 'preserve');

        if ($compacted === []
            || $sourceCount !== count($compacted) + count($preserved)
            || $preservedCount !== count($preserved)
            || $baselineCount < 1) {
            throw ManifestVerificationFailed::because(
                "manifest for [{$owner->id}] has inconsistent migration counts.",
            );
        }

        $this->assertUniqueMigrationScope([...$compacted, ...$preserved]);
        $sources = $this->sources($this->list($payload, 'sources'));
        $outputs = $this->outputs($this->list($payload, 'outputs'));
        $expectedSources = array_map(
            static fn (ManifestMigration $migration): array => [
                'path' => $migration->path,
                'sha256' => $migration->sha256,
            ],
            array_values(array_filter(
                $compacted,
                static fn (ManifestMigration $migration): bool => $migration->ownerId === $owner->id,
            )),
        );
        $actualSources = array_map(
            static fn (SourceMigration $source): array => $source->toArray(),
            $sources,
        );

        usort($expectedSources, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        usort($actualSources, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        if ($outputs === [] || $actualSources !== $expectedSources) {
            throw ManifestVerificationFailed::because(
                "manifest for [{$owner->id}] does not match its owner-specific source scope.",
            );
        }

        return new InstalledCompactionManifest(
            path: $path,
            directory: $directory,
            ownerId: $owner->id,
            ownerName: $owner->name,
            schemaFormat: $schemaFormat,
            driver: $driver,
            schemaFingerprint: $schemaFingerprint,
            sourceMigrations: $sourceCount,
            baselineMigrations: $baselineCount,
            preservedMigrations: $preservedCount,
            sources: $sources,
            outputs: $outputs,
            compacted: $compacted,
            preserved: $preserved,
        );
    }

    /**
     * @param list<mixed> $items
     * @return list<ManifestMigration>
     */
    private function migrations(array $items, string $action): array
    {
        $migrations = [];

        foreach ($items as $item) {
            $item = $this->associative($item, 'manifest migration scope entry');

            $migration = new ManifestMigration(
                $this->string($item, 'name'),
                $this->string($item, 'owner'),
                $this->string($item, 'path'),
                $this->fingerprint($item, 'sha256'),
                $this->string($item, 'classification'),
                $this->string($item, 'action'),
            );

            if ($migration->action !== $action) {
                throw ManifestVerificationFailed::because(
                    "manifest migration [{$migration->name}] is in the wrong action scope.",
                );
            }

            $migrations[] = $migration;
        }

        return $migrations;
    }

    /**
     * @param list<mixed> $items
     * @return list<SourceMigration>
     */
    private function sources(array $items): array
    {
        $sources = [];

        foreach ($items as $item) {
            $item = $this->associative($item, 'manifest source entry');

            $sources[] = new SourceMigration(
                $this->string($item, 'path'),
                $this->fingerprint($item, 'sha256'),
            );
        }

        return $sources;
    }

    /**
     * @param list<mixed> $items
     * @return list<ManifestOutput>
     */
    private function outputs(array $items): array
    {
        $outputs = [];
        $paths = [];

        foreach ($items as $item) {
            $item = $this->associative($item, 'manifest output entry');

            $output = new ManifestOutput(
                $this->string($item, 'path'),
                $this->string($item, 'table'),
                $this->fingerprint($item, 'sha256'),
            );
            $key = strtolower($output->filename);

            if (isset($paths[$key])) {
                throw ManifestVerificationFailed::because(
                    "manifest output [{$output->filename}] is duplicated.",
                );
            }

            $paths[$key] = true;
            $outputs[] = $output;
        }

        return $outputs;
    }

    /** @param list<ManifestMigration> $migrations */
    private function assertUniqueMigrationScope(array $migrations): void
    {
        $names = [];
        $paths = [];

        foreach ($migrations as $migration) {
            $name = strtolower($migration->name);
            $path = strtolower($migration->path);

            if (isset($names[$name]) || isset($paths[$path])) {
                throw ManifestVerificationFailed::because(
                    "manifest migration [{$migration->name}] is duplicated.",
                );
            }

            $names[$name] = true;
            $paths[$path] = true;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function object(array $payload, string $key): array
    {
        return $this->associative($payload[$key] ?? null, "manifest field [{$key}]");
    }

    /** @return array<string, mixed> */
    private function associative(mixed $value, string $label): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw ManifestVerificationFailed::because("{$label} must be an object.");
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw ManifestVerificationFailed::because("{$label} contains a non-string key.");
            }

            $object[$key] = $item;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<mixed>
     */
    private function list(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw ManifestVerificationFailed::because("manifest field [{$key}] must be a list.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw ManifestVerificationFailed::because("manifest field [{$key}] must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload, string $key): string
    {
        $value = $this->string($payload, $key);

        if (preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw ManifestVerificationFailed::because("manifest field [{$key}] is not a SHA-256 fingerprint.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function nonNegativeInteger(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw ManifestVerificationFailed::because("manifest field [{$key}] must be a non-negative integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function boolean(array $payload, string $key): bool
    {
        $value = $payload[$key] ?? null;

        if (! is_bool($value)) {
            throw ManifestVerificationFailed::because("manifest field [{$key}] must be a boolean.");
        }

        return $value;
    }

    private function within(string $root, string $path): bool
    {
        return str_starts_with(
            str_replace('\\', '/', $path).'/',
            rtrim(str_replace('\\', '/', $root), '/').'/',
        );
    }
}
