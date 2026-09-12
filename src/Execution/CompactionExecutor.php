<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Execution;

use Cluion\Migrafold\Activation\MigrationRecordActivationResult;
use Cluion\Migrafold\Activation\MigrationRecordActivator;
use Cluion\Migrafold\Disposition\SourceDispositionWriter;
use Cluion\Migrafold\Execution\Exception\CompactionExecutionFailed;
use Cluion\Migrafold\Output\OutputMode;
use Cluion\Migrafold\Output\OwnerAwareOutputWriter;
use Cluion\Migrafold\Planning\CompactionPlan;
use Illuminate\Database\Connection;
use Throwable;

final readonly class CompactionExecutor
{
    public function __construct(
        private OwnerAwareOutputWriter $outputs = new OwnerAwareOutputWriter(),
        private SourceDispositionWriter $sources = new SourceDispositionWriter(),
        private MigrationRecordActivator $records = new MigrationRecordActivator(),
        private SourceRecoveryCheckpointManager $recovery = new SourceRecoveryCheckpointManager(),
        private GeneratedOutputRollback $outputRollback = new GeneratedOutputRollback(),
    ) {}

    public function execute(
        CompactionPlan $plan,
        Connection $connection,
        string $migrationTable = 'migrations',
    ): CompactionExecutionResult {
        $this->outputs->execute($plan->output);
        $this->outputs->execute($plan->output, OutputMode::Write);
        $checkpoint = null;

        try {
            $this->sources->execute($plan->disposition);
            $repository = $this->records->execute(
                $plan->activation,
                $connection,
                $migrationTable,
            );

            if ($repository->alreadyActivated) {
                throw CompactionExecutionFailed::because(
                    'migration records were already activated while retired sources were still present.',
                );
            }

            $checkpoint = $this->recovery->create($plan->disposition);
            $this->sources->execute($plan->disposition, OutputMode::Write);
            $activation = $this->records->execute(
                $plan->activation,
                $connection,
                $migrationTable,
                OutputMode::Write,
            );
            $warnings = $this->recovery->discard($checkpoint);

            return $this->result($plan, $activation, $warnings);
        } catch (Throwable $exception) {
            $committed = $checkpoint === null
                ? null
                : $this->committedState($plan, $connection, $migrationTable);

            if ($committed instanceof MigrationRecordActivationResult) {
                $warnings = [
                    'activation completed, but the execution reported: '.$exception->getMessage(),
                    ...$this->recovery->discard($checkpoint),
                ];

                return $this->result($plan, $committed, $warnings);
            }

            $errors = [];

            if ($checkpoint !== null) {
                array_push($errors, ...$this->recovery->restore($plan->disposition, $checkpoint));
            }

            array_push($errors, ...$this->outputRollback->remove($plan->output));

            throw CompactionExecutionFailed::because($exception->getMessage(), $errors, $exception);
        }
    }

    private function committedState(
        CompactionPlan $plan,
        Connection $connection,
        string $migrationTable,
    ): ?MigrationRecordActivationResult {
        try {
            $result = $this->records->execute($plan->activation, $connection, $migrationTable);

            return $result->alreadyActivated ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $warnings */
    private function result(
        CompactionPlan $plan,
        MigrationRecordActivationResult $activation,
        array $warnings,
    ): CompactionExecutionResult {
        return new CompactionExecutionResult(
            $plan->output->paths(),
            count($plan->disposition->items),
            $activation,
            $warnings,
        );
    }
}
