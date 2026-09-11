<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

use Cluion\Migrafold\Output\Exception\UnsafeOutputOperation;
use Throwable;

final class BaselineOutputWriter
{
    public function __construct(
        private readonly AtomicFilePublisher $publisher = new HardLinkAtomicFilePublisher(),
    ) {}

    public function execute(
        BaselineOutputPlan $plan,
        OutputMode $mode = OutputMode::DryRun,
    ): OutputWriteResult {
        $this->assertPlanIsSafe($plan);
        $this->assertOutputDirectory($plan->directory);
        $this->assertNoCollisions($plan);

        if ($mode === OutputMode::DryRun) {
            return new OutputWriteResult($mode, $plan->paths());
        }

        $createdDirectory = false;
        $stagingDirectory = null;
        $staged = [];
        $published = [];

        try {
            if (! is_dir($plan->directory)) {
                if (! mkdir($plan->directory, 0755, true) && ! is_dir($plan->directory)) {
                    throw UnsafeOutputOperation::because(
                        "output directory [{$plan->directory}] could not be created.",
                    );
                }

                $createdDirectory = true;
            }

            $this->assertOutputDirectory($plan->directory, true);
            $this->assertNoCollisions($plan);
            $stagingDirectory = $plan->directory.DIRECTORY_SEPARATOR.'.migrafold-write-'.bin2hex(random_bytes(12));

            if (! mkdir($stagingDirectory, 0700)) {
                throw UnsafeOutputOperation::because('a private staging directory could not be created.');
            }

            foreach ($plan->files() as $file) {
                $path = $stagingDirectory.DIRECTORY_SEPARATOR.$file->filename;
                $staged[] = $path;
                $bytes = file_put_contents($path, $file->contents, LOCK_EX);

                if ($bytes !== strlen($file->contents) || hash_file('sha256', $path) !== $file->sha256) {
                    throw UnsafeOutputOperation::because(
                        "staged output [{$file->filename}] could not be verified.",
                    );
                }

                if (! chmod($path, 0644)) {
                    throw UnsafeOutputOperation::because(
                        "staged output [{$file->filename}] permissions could not be set.",
                    );
                }

            }

            foreach ($plan->files() as $offset => $file) {
                $target = $plan->directory.DIRECTORY_SEPARATOR.$file->filename;

                $this->publisher->publish($staged[$offset], $target);

                $published[] = $target;
            }

            $this->removeFiles($staged);
            $staged = [];

            if (! rmdir($stagingDirectory)) {
                throw UnsafeOutputOperation::because('the private staging directory could not be removed.');
            }

            $stagingDirectory = null;
        } catch (Throwable $exception) {
            $this->removeFiles(array_reverse($published));
            $this->removeFiles($staged);

            if ($stagingDirectory !== null) {
                @rmdir($stagingDirectory);
            }

            if ($createdDirectory) {
                @rmdir($plan->directory);
            }

            if ($exception instanceof UnsafeOutputOperation) {
                throw $exception;
            }

            throw UnsafeOutputOperation::because($exception->getMessage());
        }

        return new OutputWriteResult($mode, $plan->paths());
    }

    private function assertPlanIsSafe(BaselineOutputPlan $plan): void
    {
        $portableDirectory = str_replace('\\', '/', $plan->directory);
        $segments = explode('/', $portableDirectory);
        $absolute = str_starts_with($portableDirectory, '/')
            || preg_match('/^[a-zA-Z]:\//', $portableDirectory) === 1;

        if (
            str_contains($plan->directory, "\0")
            || ! $absolute
            || $portableDirectory === '/'
            || preg_match('/^[a-zA-Z]:\/?$/', $portableDirectory) === 1
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw UnsafeOutputOperation::because(
                "output directory [{$plan->directory}] is unsafe.",
            );
        }

        $filenames = [];

        foreach ($plan->files() as $file) {
            if (
                $file->filename === ''
                || str_contains($file->filename, "\0")
                || basename($file->filename) !== $file->filename
                || str_contains($file->filename, '\\')
            ) {
                throw UnsafeOutputOperation::because(
                    "planned output filename [{$file->filename}] is unsafe.",
                );
            }

            if ($file->table !== null && ! str_starts_with($file->contents, "<?php\n")) {
                throw UnsafeOutputOperation::because(
                    "planned migration [{$file->filename}] does not contain a PHP file.",
                );
            }

            $key = strtolower($file->filename);

            if (isset($filenames[$key])) {
                throw UnsafeOutputOperation::because(
                    "duplicate planned output filename [{$file->filename}].",
                );
            }

            $filenames[$key] = true;
        }
    }

    private function assertOutputDirectory(string $directory, bool $mustBeWritable = false): void
    {
        if (is_link($directory)) {
            throw UnsafeOutputOperation::because("output directory [{$directory}] must not be a symbolic link.");
        }

        if (file_exists($directory) && ! is_dir($directory)) {
            throw UnsafeOutputOperation::because("output path [{$directory}] is not a directory.");
        }

        if ($mustBeWritable && ! is_writable($directory)) {
            throw UnsafeOutputOperation::because("output directory [{$directory}] is not writable.");
        }
    }

    private function assertNoCollisions(BaselineOutputPlan $plan): void
    {
        if (! is_dir($plan->directory)) {
            return;
        }

        $entries = scandir($plan->directory);

        if ($entries === false) {
            throw UnsafeOutputOperation::because(
                "output directory [{$plan->directory}] could not be inspected.",
            );
        }

        $existing = [];

        foreach ($entries as $entry) {
            $existing[strtolower($entry)] = $entry;
        }

        foreach ($plan->files() as $file) {
            if (isset($existing[strtolower($file->filename)])) {
                throw UnsafeOutputOperation::because(
                    "output [{$file->filename}] collides with existing entry [{$existing[strtolower($file->filename)]}].",
                );
            }
        }
    }

    /** @param list<string> $paths */
    private function removeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
    }
}
