<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Execution;

use Cluion\Migrafold\Disposition\Exception\SourceDispositionFailed;
use Cluion\Migrafold\Disposition\NativeSourceFileOperator;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Disposition\SourceDispositionPlan;
use Cluion\Migrafold\Disposition\SourceFileOperator;
use Cluion\Migrafold\Execution\Exception\CompactionExecutionFailed;
use Throwable;

final readonly class SourceRecoveryCheckpointManager
{
    public function __construct(
        private SourceFileOperator $files = new NativeSourceFileOperator(),
    ) {}

    public function create(SourceDispositionPlan $plan): SourceRecoveryCheckpoint
    {
        $token = bin2hex(random_bytes(12));
        $directories = [];
        $copies = [];

        try {
            foreach ($plan->items as $item) {
                $directory = $item->migrationDirectory.DIRECTORY_SEPARATOR.'.migrafold-recovery-'.$token;

                if (! isset($directories[$directory])) {
                    if (file_exists($directory) || is_link($directory) || ! mkdir($directory, 0700)) {
                        throw CompactionExecutionFailed::because(
                            "recovery checkpoint directory [{$directory}] could not be created safely.",
                        );
                    }

                    $directories[$directory] = true;
                }

                $path = $directory.DIRECTORY_SEPARATOR.basename($item->source);
                $this->files->link($item->source, $path);
                $copy = new RecoveryCopy($item, $path);
                $copies[] = $copy;
                $this->assertFingerprint($path, $item->sha256, 'recovery copy');
            }
        } catch (Throwable $exception) {
            $errors = $this->discard(new SourceRecoveryCheckpoint($copies, array_keys($directories)));

            if ($exception instanceof CompactionExecutionFailed) {
                throw CompactionExecutionFailed::because($exception->getMessage(), $errors, $exception);
            }

            throw CompactionExecutionFailed::because(
                'source recovery checkpoint could not be created: '.$exception->getMessage(),
                $errors,
                $exception,
            );
        }

        return new SourceRecoveryCheckpoint($copies, array_keys($directories));
    }

    /** @return list<string> */
    public function restore(
        SourceDispositionPlan $plan,
        SourceRecoveryCheckpoint $checkpoint,
    ): array {
        $errors = [];

        foreach ($checkpoint->copies as $copy) {
            try {
                $this->assertFingerprint($copy->path, $copy->source->sha256, 'recovery copy');

                if (file_exists($copy->source->source) || is_link($copy->source->source)) {
                    $this->assertFingerprint(
                        $copy->source->source,
                        $copy->source->sha256,
                        'restored source',
                    );
                } else {
                    $this->files->link($copy->path, $copy->source->source);
                    $this->assertFingerprint(
                        $copy->source->source,
                        $copy->source->sha256,
                        'restored source',
                    );
                }
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        if ($plan->mode === SourceDispositionMode::Archive) {
            foreach ($plan->items as $item) {
                $destination = $item->destination;

                if ($destination === null || (! file_exists($destination) && ! is_link($destination))) {
                    continue;
                }

                try {
                    $this->assertFingerprint($destination, $item->sha256, 'archive copy');
                    $this->files->unlink($destination);
                } catch (Throwable $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
        }

        array_push($errors, ...$this->discard($checkpoint));

        return $errors;
    }

    /** @return list<string> */
    public function discard(SourceRecoveryCheckpoint $checkpoint): array
    {
        $errors = [];

        foreach (array_reverse($checkpoint->copies) as $copy) {
            if (! file_exists($copy->path) && ! is_link($copy->path)) {
                continue;
            }

            try {
                $this->files->unlink($copy->path);
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        foreach (array_reverse($checkpoint->directories) as $directory) {
            if (is_dir($directory) && ! @rmdir($directory)) {
                $errors[] = "recovery checkpoint directory [{$directory}] could not be removed.";
            }
        }

        return $errors;
    }

    private function assertFingerprint(string $path, string $sha256, string $label): void
    {
        if (! is_file($path) || is_link($path)) {
            throw SourceDispositionFailed::because("{$label} [{$path}] is missing or unsafe.");
        }

        $actual = hash_file('sha256', $path);

        if ($actual === false || ! hash_equals($sha256, $actual)) {
            throw SourceDispositionFailed::because("{$label} [{$path}] changed unexpectedly.");
        }
    }
}
