<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Disposition;

use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Discovery\MigrationOwner;
use Cluion\Migrafold\Disposition\Exception\SourceDispositionFailed;
use Cluion\Migrafold\Output\OwnerAwareOutputPlan;

final class SourceDispositionPlanner
{
    public const ARCHIVE_DIRECTORY = '.migrafold-archive';

    public function plan(
        string $projectRoot,
        MigrationCatalog $catalog,
        OwnerAwareOutputPlan $baseline,
        SourceDispositionMode $mode,
        ?string $archiveId = null,
    ): SourceDispositionPlan {
        $root = realpath($projectRoot);

        if ($root === false || ! is_dir($root)) {
            throw SourceDispositionFailed::because("project root [{$projectRoot}] is not a readable directory.");
        }

        $this->assertArchiveId($mode, $archiveId);
        $owners = $this->owners($catalog);
        $baselineOwners = [];
        $protectedFiles = [];
        $protectedPaths = [];

        foreach ($baseline->owners as $ownerPlan) {
            $ownerKey = strtolower($ownerPlan->ownerId);

            if (isset($baselineOwners[$ownerKey])) {
                throw SourceDispositionFailed::because("baseline owner [{$ownerPlan->ownerId}] is duplicated.");
            }

            $owner = $owners[$ownerKey] ?? null;

            if ($owner === null) {
                throw SourceDispositionFailed::because(
                    "baseline output references unknown owner [{$ownerPlan->ownerId}].",
                );
            }

            $ownerDirectory = realpath($owner->migrationDirectory);
            $baselineDirectory = realpath($ownerPlan->output->directory);

            if (
                $ownerDirectory === false
                || $baselineDirectory === false
                || $this->normalizePath($baselineDirectory) !== $this->normalizePath($ownerDirectory)
            ) {
                throw SourceDispositionFailed::because(
                    "baseline output directory for [{$ownerPlan->ownerId}] does not match its migration directory.",
                );
            }

            $baselineOwners[$ownerKey] = true;

            foreach ($ownerPlan->output->files() as $file) {
                $path = $ownerDirectory.DIRECTORY_SEPARATOR.$file->filename;
                $key = strtolower($this->normalizePath($path));

                if (isset($protectedPaths[$key])) {
                    throw SourceDispositionFailed::because("baseline output [{$path}] is duplicated.");
                }

                $protectedPaths[$key] = true;
                $protectedFiles[] = new ProtectedBaselineFile(
                    $owner->id,
                    $ownerDirectory,
                    $path,
                    $file->sha256,
                );
            }
        }

        $items = [];
        $sourcePaths = [];
        $destinationPaths = [];

        foreach ($catalog->migrations as $migration) {
            $ownerKey = strtolower($migration->ownerId);
            $owner = $owners[$ownerKey] ?? null;

            if ($owner === null || ! isset($baselineOwners[$ownerKey])) {
                throw SourceDispositionFailed::because(
                    "source [{$migration->source->path}] is not covered by a baseline output owner.",
                );
            }

            $resolved = realpath($migration->absolutePath);
            $expected = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $migration->source->path);
            $ownerDirectory = realpath($owner->migrationDirectory);

            if (
                $resolved === false
                || $ownerDirectory === false
                || ! is_file($resolved)
                || is_link($migration->absolutePath)
                || $resolved !== $expected
                || $this->normalizePath(dirname($resolved)) !== $this->normalizePath($ownerDirectory)
            ) {
                throw SourceDispositionFailed::because(
                    "source [{$migration->source->path}] is missing or does not belong directly to owner [{$owner->id}].",
                );
            }

            $sourceKey = strtolower($this->normalizePath($resolved));

            if (isset($protectedPaths[$sourceKey])) {
                throw SourceDispositionFailed::because(
                    "source [{$migration->source->path}] overlaps a protected baseline output.",
                );
            }

            if (isset($sourcePaths[$sourceKey])) {
                throw SourceDispositionFailed::because("source [{$migration->source->path}] is duplicated.");
            }

            $destination = $mode === SourceDispositionMode::Archive
                ? $ownerDirectory.DIRECTORY_SEPARATOR.self::ARCHIVE_DIRECTORY
                    .DIRECTORY_SEPARATOR.$archiveId.DIRECTORY_SEPARATOR.basename($resolved)
                : null;

            if ($destination !== null) {
                $destinationKey = strtolower($this->normalizePath($destination));

                if (isset($destinationPaths[$destinationKey]) || isset($protectedPaths[$destinationKey])) {
                    throw SourceDispositionFailed::because(
                        "archive destination [{$destination}] is duplicated or protected.",
                    );
                }

                $destinationPaths[$destinationKey] = true;
            }

            $sourcePaths[$sourceKey] = true;
            $items[] = new PlannedSourceDisposition(
                ownerId: $owner->id,
                migrationDirectory: $ownerDirectory,
                source: $resolved,
                relativeSource: $migration->source->path,
                sha256: $migration->source->sha256,
                destination: $destination,
            );
        }

        if ($items === []) {
            throw SourceDispositionFailed::because('at least one discovered source migration is required.');
        }

        usort(
            $items,
            static fn (PlannedSourceDisposition $left, PlannedSourceDisposition $right): int => strcmp(
                $left->relativeSource,
                $right->relativeSource,
            ),
        );
        usort(
            $protectedFiles,
            static fn (ProtectedBaselineFile $left, ProtectedBaselineFile $right): int => strcmp(
                $left->path,
                $right->path,
            ),
        );

        return new SourceDispositionPlan($root, $mode, $items, $protectedFiles);
    }

    private function assertArchiveId(SourceDispositionMode $mode, ?string $archiveId): void
    {
        if ($mode === SourceDispositionMode::Delete && $archiveId !== null) {
            throw SourceDispositionFailed::because('delete mode must not define an archive id.');
        }

        if (
            $mode === SourceDispositionMode::Archive
            && ($archiveId === null || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $archiveId) !== 1)
        ) {
            throw SourceDispositionFailed::because('archive mode requires a safe archive id.');
        }
    }

    /** @return array<string, MigrationOwner> */
    private function owners(MigrationCatalog $catalog): array
    {
        $owners = [];

        foreach ($catalog->owners as $owner) {
            $owners[strtolower($owner->id)] = $owner;
        }

        return $owners;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/\\'));
    }
}
