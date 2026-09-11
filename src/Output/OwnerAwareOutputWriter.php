<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

use Cluion\Migrafold\Output\Exception\UnsafeOutputOperation;
use Throwable;

final class OwnerAwareOutputWriter
{
    public function __construct(
        private readonly BaselineOutputWriter $writer = new BaselineOutputWriter(),
    ) {}

    public function execute(
        OwnerAwareOutputPlan $plan,
        OutputMode $mode = OutputMode::DryRun,
    ): OwnerAwareOutputWriteResult {
        $this->assertPlanIsSafe($plan);

        $results = [];

        foreach ($plan->owners as $owner) {
            $result = $this->writer->execute($owner->output, OutputMode::DryRun);
            $results[] = new OwnerOutputWriteResult(
                $owner->ownerId,
                $owner->ownerName,
                $result->paths,
            );
        }

        if ($mode === OutputMode::DryRun) {
            return new OwnerAwareOutputWriteResult($mode, $results);
        }

        /** @var list<array{plan: OwnerOutputPlan, directory_existed: bool}> $completed */
        $completed = [];

        try {
            foreach ($plan->owners as $owner) {
                $directoryExisted = is_dir($owner->output->directory);
                $this->writer->execute($owner->output, OutputMode::Write);
                $completed[] = [
                    'plan' => $owner,
                    'directory_existed' => $directoryExisted,
                ];
            }
        } catch (Throwable $exception) {
            $rollbackErrors = $this->rollback(array_reverse($completed));

            if ($rollbackErrors !== []) {
                throw UnsafeOutputOperation::because(
                    $exception->getMessage().' Multi-owner rollback was incomplete: '.implode(' ', $rollbackErrors),
                );
            }

            if ($exception instanceof UnsafeOutputOperation) {
                throw $exception;
            }

            throw UnsafeOutputOperation::because($exception->getMessage());
        }

        return new OwnerAwareOutputWriteResult($mode, $results);
    }

    private function assertPlanIsSafe(OwnerAwareOutputPlan $plan): void
    {
        if ($plan->owners === []) {
            throw UnsafeOutputOperation::because('at least one owner output plan is required.');
        }

        $owners = [];
        $directories = [];

        foreach ($plan->owners as $owner) {
            new BaselineManifestOwner($owner->ownerId, $owner->ownerName);
            $ownerKey = strtolower($owner->ownerId);
            $directoryKey = strtolower(str_replace('\\', '/', rtrim($owner->output->directory, '/\\')));

            if (isset($owners[$ownerKey])) {
                throw UnsafeOutputOperation::because("duplicate owner output plan [{$owner->ownerId}].");
            }

            if (isset($directories[$directoryKey])) {
                throw UnsafeOutputOperation::because(
                    "owner output plans [{$directories[$directoryKey]}] and [{$owner->ownerId}] share a directory.",
                );
            }

            $owners[$ownerKey] = true;
            $directories[$directoryKey] = $owner->ownerId;
        }
    }

    /**
     * @param list<array{plan: OwnerOutputPlan, directory_existed: bool}> $completed
     * @return list<string>
     */
    private function rollback(array $completed): array
    {
        $errors = [];

        foreach ($completed as $entry) {
            $output = $entry['plan']->output;

            foreach (array_reverse($output->files()) as $file) {
                $path = $output->directory.DIRECTORY_SEPARATOR.$file->filename;

                if ((is_file($path) || is_link($path)) && ! @unlink($path)) {
                    $errors[] = "output [{$path}] could not be removed.";
                }
            }

            if (! $entry['directory_existed'] && is_dir($output->directory) && ! @rmdir($output->directory)) {
                $errors[] = "created output directory [{$output->directory}] could not be removed.";
            }
        }

        return $errors;
    }
}
