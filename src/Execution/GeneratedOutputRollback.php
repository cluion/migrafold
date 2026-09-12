<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Execution;

use Cluion\Migrafold\Disposition\NativeSourceFileOperator;
use Cluion\Migrafold\Disposition\SourceFileOperator;
use Cluion\Migrafold\Output\OwnerAwareOutputPlan;
use Throwable;

final readonly class GeneratedOutputRollback
{
    public function __construct(
        private SourceFileOperator $files = new NativeSourceFileOperator(),
    ) {}

    /** @return list<string> */
    public function remove(OwnerAwareOutputPlan $plan): array
    {
        $errors = [];

        foreach (array_reverse($plan->owners) as $owner) {
            foreach (array_reverse($owner->output->files()) as $file) {
                $path = $owner->output->directory.DIRECTORY_SEPARATOR.$file->filename;

                if (! file_exists($path) && ! is_link($path)) {
                    continue;
                }

                if (! is_file($path) || is_link($path) || hash_file('sha256', $path) !== $file->sha256) {
                    $errors[] = "generated output [{$path}] changed and was not removed.";

                    continue;
                }

                try {
                    $this->files->unlink($path);
                } catch (Throwable $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
        }

        return $errors;
    }
}
