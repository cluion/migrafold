<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Disposition;

use Cluion\Migrafold\Disposition\Exception\SourceDispositionFailed;
use Cluion\Migrafold\Output\BaselineOutputPlan;
use Cluion\Migrafold\Output\OutputMode;
use Throwable;

final class SourceDispositionWriter
{
    public function __construct(
        private readonly SourceFileOperator $files = new NativeSourceFileOperator(),
    ) {}

    public function execute(
        SourceDispositionPlan $plan,
        OutputMode $mode = OutputMode::DryRun,
    ): SourceDispositionResult {
        $this->preflight($plan);

        if ($mode === OutputMode::Write) {
            match ($plan->mode) {
                SourceDispositionMode::Archive => $this->archive($plan),
                SourceDispositionMode::Delete => $this->delete($plan),
            };
        }

        return new SourceDispositionResult($plan->mode, $mode, $plan->items);
    }

    private function preflight(SourceDispositionPlan $plan): void
    {
        $root = realpath($plan->projectRoot);

        if ($root === false || $root !== $plan->projectRoot || $plan->items === [] || $plan->protectedFiles === []) {
            throw SourceDispositionFailed::because('disposition plan has an invalid project root or empty scope.');
        }

        $sources = [];
        $destinations = [];
        $ownerDirectories = [];

        foreach ($plan->items as $item) {
            if (preg_match('/\A[a-z][a-z0-9.-]*:[A-Za-z][A-Za-z0-9_.-]*\z/', $item->ownerId) !== 1) {
                throw SourceDispositionFailed::because("source owner [{$item->ownerId}] is invalid.");
            }

            $ownerKey = strtolower($item->ownerId);
            $directory = realpath($item->migrationDirectory);

            if (
                $directory === false
                || $directory !== $item->migrationDirectory
                || ! is_dir($directory)
                || is_link($item->migrationDirectory)
            ) {
                throw SourceDispositionFailed::because(
                    "migration directory [{$item->migrationDirectory}] for [{$item->ownerId}] is unsafe.",
                );
            }

            $this->assertWithinRoot($root, $directory, 'migration directory');

            if (isset($ownerDirectories[$ownerKey]) && $ownerDirectories[$ownerKey] !== $directory) {
                throw SourceDispositionFailed::because(
                    "source owner [{$item->ownerId}] has multiple migration directories.",
                );
            }

            $ownerDirectories[$ownerKey] = $directory;
            $this->assertRegularFile($root, $item->source, $item->sha256, 'source migration');
            $sourceKey = strtolower($this->normalizePath($item->source));
            $expectedSource = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $item->relativeSource);

            if (
                $item->source !== $expectedSource
                || dirname($item->source) !== $directory
                || basename($item->relativeSource) !== basename($item->source)
            ) {
                throw SourceDispositionFailed::because(
                    "source migration [{$item->relativeSource}] does not match its planned owner path.",
                );
            }

            if (isset($sources[$sourceKey])) {
                throw SourceDispositionFailed::because(
                    "source migration [{$item->relativeSource}] is duplicated.",
                );
            }

            $sources[$sourceKey] = true;

            if ($plan->mode === SourceDispositionMode::Archive) {
                if ($item->destination === null) {
                    throw SourceDispositionFailed::because(
                        "archive source [{$item->relativeSource}] has no destination.",
                    );
                }

                $this->assertWithinRoot($root, $item->destination, 'archive destination');
                $this->assertNoSymlinkComponents($root, dirname($item->destination));
                $destinationKey = strtolower($this->normalizePath($item->destination));
                $archiveDirectory = dirname(dirname($item->destination));
                $archiveId = basename(dirname($item->destination));

                if (
                    basename($item->destination) !== basename($item->source)
                    || basename($archiveDirectory) !== SourceDispositionPlanner::ARCHIVE_DIRECTORY
                    || dirname($archiveDirectory) !== $directory
                    || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $archiveId) !== 1
                ) {
                    throw SourceDispositionFailed::because(
                        "archive destination [{$item->destination}] does not match its source owner.",
                    );
                }

                if (isset($destinations[$destinationKey])) {
                    throw SourceDispositionFailed::because(
                        "archive destination [{$item->destination}] is duplicated.",
                    );
                }

                $this->assertNoCaseInsensitiveCollision($item->destination);
                $destinations[$destinationKey] = true;
            } elseif ($item->destination !== null) {
                throw SourceDispositionFailed::because(
                    "delete source [{$item->relativeSource}] must not have an archive destination.",
                );
            }
        }

        $protectedPaths = [];
        $protectedOwners = [];
        $baselineOwnerDirectories = [];

        foreach ($plan->protectedFiles as $file) {
            $ownerKey = strtolower($file->ownerId);
            $directory = realpath($file->migrationDirectory);
            $filename = basename($file->path);

            if (
                preg_match('/\A[a-z][a-z0-9.-]*:[A-Za-z][A-Za-z0-9_.-]*\z/', $file->ownerId) !== 1
                || $directory === false
                || $directory !== $file->migrationDirectory
                || (isset($ownerDirectories[$ownerKey]) && $ownerDirectories[$ownerKey] !== $directory)
                || (isset($baselineOwnerDirectories[$ownerKey]) && $baselineOwnerDirectories[$ownerKey] !== $directory)
                || dirname($file->path) !== $directory
                || (
                    $filename !== BaselineOutputPlan::MANIFEST_FILENAME
                    && preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_create_[a-z0-9_]+_baseline\.php$/', $filename) !== 1
                )
            ) {
                throw SourceDispositionFailed::because(
                    "protected baseline output [{$file->path}] does not match owner [{$file->ownerId}].",
                );
            }

            $this->assertWithinRoot($root, $directory, 'baseline migration directory');
            $this->assertRegularFile($root, $file->path, $file->sha256, 'protected baseline output');
            $key = strtolower($this->normalizePath($file->path));

            if (isset($protectedPaths[$key]) || isset($sources[$key]) || isset($destinations[$key])) {
                throw SourceDispositionFailed::because(
                    "protected baseline output [{$file->path}] is duplicated or overlaps disposition scope.",
                );
            }

            $protectedPaths[$key] = true;
            $protectedOwners[$ownerKey] = true;
            $baselineOwnerDirectories[$ownerKey] = $directory;
        }

        foreach ($ownerDirectories as $ownerKey => $directory) {
            if (! isset($protectedOwners[$ownerKey])) {
                throw SourceDispositionFailed::because(
                    "source owner directory [{$directory}] has no protected baseline output.",
                );
            }
        }
    }

    private function archive(SourceDispositionPlan $plan): void
    {
        $createdDirectories = [];
        $linked = [];
        $removed = [];

        try {
            foreach ($plan->items as $item) {
                $destination = $item->destination;

                if ($destination === null) {
                    throw SourceDispositionFailed::because('archive plan lost a destination after preflight.');
                }

                array_push($createdDirectories, ...$this->createDirectory(dirname($destination), $plan->projectRoot));
                $this->files->link($item->source, $destination);
                $linked[] = $item;
                $this->assertFingerprint($destination, $item->sha256, 'archived migration');
            }

            $this->revalidateSources($plan->items);

            foreach ($plan->items as $item) {
                $this->files->unlink($item->source);
                $removed[] = $item;
            }
        } catch (Throwable $exception) {
            $rollbackErrors = $this->restoreSources(array_reverse($removed), true);
            array_push($rollbackErrors, ...$this->removeArchiveLinks(array_reverse($linked)));
            $createdDirectories = array_values(array_unique($createdDirectories));
            array_push($rollbackErrors, ...$this->removeDirectories(array_reverse($createdDirectories)));
            $this->rethrow($exception, $rollbackErrors);
        }
    }

    private function delete(SourceDispositionPlan $plan): void
    {
        $token = bin2hex(random_bytes(12));
        $stagingDirectories = [];
        $staged = [];
        $removed = [];

        try {
            foreach ($plan->items as $item) {
                $directory = dirname($item->source).DIRECTORY_SEPARATOR.'.migrafold-disposition-'.$token;

                if (! isset($stagingDirectories[$directory])) {
                    if (! mkdir($directory, 0700)) {
                        throw SourceDispositionFailed::because(
                            "private disposition directory [{$directory}] could not be created.",
                        );
                    }

                    $stagingDirectories[$directory] = true;
                }

                $target = $directory.DIRECTORY_SEPARATOR.basename($item->source);
                $this->files->link($item->source, $target);
                $staged[] = [$item, $target];
                $this->assertFingerprint($target, $item->sha256, 'staged migration');
            }

            $this->revalidateSources($plan->items);

            foreach ($plan->items as $item) {
                $this->files->unlink($item->source);
                $removed[] = $item;
            }
        } catch (Throwable $exception) {
            $rollbackErrors = $this->restoreFromStaging(array_reverse($removed), $staged);
            array_push($rollbackErrors, ...$this->removeStaging($staged, array_keys($stagingDirectories)));
            $this->rethrow($exception, $rollbackErrors);
        }

        $cleanupErrors = $this->removeStaging($staged, array_keys($stagingDirectories));

        if ($cleanupErrors !== []) {
            throw SourceDispositionFailed::because(
                'sources were deleted, but private staging cleanup was incomplete: '.implode(' ', $cleanupErrors),
            );
        }
    }

    /** @param list<PlannedSourceDisposition> $items */
    private function revalidateSources(array $items): void
    {
        foreach ($items as $item) {
            $this->assertFingerprint($item->source, $item->sha256, 'source migration');
        }
    }

    /**
     * @param list<PlannedSourceDisposition> $items
     * @return list<string>
     */
    private function restoreSources(array $items, bool $fromArchive): array
    {
        $errors = [];

        foreach ($items as $item) {
            $backup = $fromArchive ? $item->destination : null;

            if ($backup === null) {
                $errors[] = "source [{$item->source}] has no recovery copy.";

                continue;
            }

            try {
                $this->files->link($backup, $item->source);
                $this->assertFingerprint($item->source, $item->sha256, 'restored source migration');
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return $errors;
    }

    /**
     * @param list<PlannedSourceDisposition> $removed
     * @param list<array{0: PlannedSourceDisposition, 1: string}> $staged
     * @return list<string>
     */
    private function restoreFromStaging(array $removed, array $staged): array
    {
        $backups = [];

        foreach ($staged as [$item, $path]) {
            $backups[strtolower($this->normalizePath($item->source))] = $path;
        }

        $errors = [];

        foreach ($removed as $item) {
            $backup = $backups[strtolower($this->normalizePath($item->source))] ?? null;

            if ($backup === null) {
                $errors[] = "source [{$item->source}] has no staged recovery copy.";

                continue;
            }

            try {
                $this->files->link($backup, $item->source);
                $this->assertFingerprint($item->source, $item->sha256, 'restored source migration');
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return $errors;
    }

    /**
     * @param list<PlannedSourceDisposition> $items
     * @return list<string>
     */
    private function removeArchiveLinks(array $items): array
    {
        $errors = [];

        foreach ($items as $item) {
            if ($item->destination !== null) {
                $this->tryUnlink($item->destination, $errors);
            }
        }

        return $errors;
    }

    /**
     * @param list<array{0: PlannedSourceDisposition, 1: string}> $staged
     * @param list<string> $directories
     * @return list<string>
     */
    private function removeStaging(array $staged, array $directories): array
    {
        $errors = [];

        foreach (array_reverse($staged) as [, $path]) {
            $this->tryUnlink($path, $errors);
        }

        array_push($errors, ...$this->removeDirectories(array_reverse($directories)));

        return $errors;
    }

    /** @param list<string> $errors */
    private function tryUnlink(string $path, array &$errors): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        try {
            $this->files->unlink($path);
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    /**
     * @return list<string>
     */
    private function createDirectory(string $directory, string $root): array
    {
        $this->assertWithinRoot($root, $directory, 'archive directory');
        $missing = [];
        $cursor = $directory;

        while (! file_exists($cursor)) {
            $missing[] = $cursor;
            $parent = dirname($cursor);

            if ($parent === $cursor) {
                throw SourceDispositionFailed::because("archive directory [{$directory}] has no safe parent.");
            }

            $cursor = $parent;
        }

        if (! is_dir($cursor) || is_link($cursor)) {
            throw SourceDispositionFailed::because("archive parent [{$cursor}] is not a real directory.");
        }

        $created = [];

        foreach (array_reverse($missing) as $path) {
            if (! mkdir($path, 0755)) {
                throw SourceDispositionFailed::because("archive directory [{$path}] could not be created.");
            }

            $created[] = $path;
        }

        return $created;
    }

    /**
     * @param list<string> $directories
     * @return list<string>
     */
    private function removeDirectories(array $directories): array
    {
        $errors = [];

        foreach ($directories as $directory) {
            if (is_dir($directory) && ! @rmdir($directory)) {
                $errors[] = "created directory [{$directory}] could not be removed.";
            }
        }

        return $errors;
    }

    private function assertRegularFile(string $root, string $path, string $sha256, string $label): void
    {
        $this->assertWithinRoot($root, $path, $label);

        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            throw SourceDispositionFailed::because("{$label} [{$path}] is not a readable regular file.");
        }

        $resolved = realpath($path);

        if ($resolved === false || $resolved !== $path) {
            throw SourceDispositionFailed::because("{$label} [{$path}] does not resolve to its planned path.");
        }

        $this->assertFingerprint($path, $sha256, $label);
    }

    private function assertFingerprint(string $path, string $sha256, string $label): void
    {
        $actual = hash_file('sha256', $path);

        if ($actual === false || ! hash_equals($sha256, $actual)) {
            throw SourceDispositionFailed::because("{$label} [{$path}] changed after planning.");
        }
    }

    private function assertWithinRoot(string $root, string $path, string $label): void
    {
        $this->assertSafeAbsolutePath($path, $label);
        $normalizedRoot = rtrim($this->normalizePath($root), '/');
        $normalizedPath = $this->normalizePath($path);

        if (! str_starts_with($normalizedPath, $normalizedRoot.'/')) {
            throw SourceDispositionFailed::because("{$label} [{$path}] is outside the project root.");
        }
    }

    private function assertSafeAbsolutePath(string $path, string $label): void
    {
        $normalized = $this->normalizePath($path);
        $segments = explode('/', $normalized);
        $absolute = str_starts_with($normalized, '/') || preg_match('/\A[A-Za-z]:\//', $normalized) === 1;

        if (
            $path === ''
            || str_contains($path, "\0")
            || ! $absolute
            || $normalized === '/'
            || preg_match('/\A[A-Za-z]:\z/', $normalized) === 1
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw SourceDispositionFailed::because("{$label} [{$path}] is not a safe absolute path.");
        }
    }

    private function assertNoSymlinkComponents(string $root, string $path): void
    {
        $relative = substr($this->normalizePath($path), strlen(rtrim($this->normalizePath($root), '/')) + 1);
        $cursor = $root;

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '') {
                continue;
            }

            $cursor .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($cursor)) {
                throw SourceDispositionFailed::because("path component [{$cursor}] must not be a symbolic link.");
            }
        }
    }

    private function assertNoCaseInsensitiveCollision(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            throw SourceDispositionFailed::because("archive directory [{$directory}] could not be inspected.");
        }

        $filename = strtolower(basename($path));

        foreach ($entries as $entry) {
            if (strtolower($entry) === $filename) {
                throw SourceDispositionFailed::because(
                    "archive destination [{$path}] collides with existing entry [{$entry}].",
                );
            }
        }
    }

    /** @param list<string> $rollbackErrors */
    private function rethrow(Throwable $exception, array $rollbackErrors): never
    {
        if ($rollbackErrors !== []) {
            throw SourceDispositionFailed::because(
                $exception->getMessage().' Rollback was incomplete: '.implode(' ', $rollbackErrors),
            );
        }

        if ($exception instanceof SourceDispositionFailed) {
            throw $exception;
        }

        throw SourceDispositionFailed::because($exception->getMessage());
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/\\'));
    }
}
