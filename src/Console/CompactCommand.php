<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Console;

use Cluion\Migrafold\Activation\MigrationTableNameResolver;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Execution\CompactionExecutionResult;
use Cluion\Migrafold\Execution\CompactionExecutor;
use Cluion\Migrafold\Planning\CompactionPlan;
use Cluion\Migrafold\Planning\CompactionPlanner;
use Cluion\Migrafold\Planning\MigrationSourceAdapterFactory;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use Throwable;

final class CompactCommand extends Command
{
    protected $signature = 'migrafold:compact
        {--connection= : Database connection to inspect and update}
        {--date= : Baseline filename date in YYYY_MM_DD}
        {--delete : Permanently delete retired source migrations instead of archiving them}
        {--archive-id= : Safe archive identifier used by archive mode}
        {--confirm= : Exact plan fingerprint required to execute}
        {--confirm-delete= : Repeat the exact plan fingerprint to approve permanent deletion}';

    protected $description = 'Execute a verified migration compaction with recoverable filesystem changes';

    public function __construct(
        private readonly Application $application,
        private readonly DatabaseManager $databases,
        private readonly Container $container,
        private readonly MigrationTableNameResolver $migrationTables,
        private readonly MigrationSourceAdapterFactory $adapters,
        private readonly CompactionPlanner $planner,
        private readonly CompactionExecutor $executor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $disposition = $this->option('delete') === true
                ? SourceDispositionMode::Delete
                : SourceDispositionMode::Archive;
            $this->validateDeleteConfirmationOption($disposition);
            $date = $this->stringOption('date') ?? $this->now()->format('Y_m_d');
            $archiveId = $this->archiveId($disposition);
            $connection = $this->connection();
            $root = $this->application->basePath();
            $migrationTable = $this->migrationTables->resolve();
            $plan = $this->planner->plan(
                $root,
                $connection,
                $this->adapters->forApplication($root, $this->container),
                $date,
                $disposition,
                $archiveId,
                $migrationTable,
            );

            $this->renderScope($plan);
            $this->requireFingerprint('confirm', $plan->fingerprint());

            if ($disposition === SourceDispositionMode::Delete) {
                $this->requireFingerprint('confirm-delete', $plan->fingerprint());
            }

            $result = $this->executor->execute($plan, $connection);
            $this->renderResult($result);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function connection(): Connection
    {
        return $this->databases->connection($this->stringOption('connection'));
    }

    private function archiveId(SourceDispositionMode $disposition): ?string
    {
        $configured = $this->stringOption('archive-id');

        if ($disposition === SourceDispositionMode::Delete) {
            if ($configured !== null) {
                throw new InvalidArgumentException('--archive-id cannot be combined with --delete.');
            }

            return null;
        }

        return $configured ?? $this->now()->format('Ymd\THis\Z');
    }

    private function validateDeleteConfirmationOption(SourceDispositionMode $disposition): void
    {
        if (
            $disposition === SourceDispositionMode::Archive
            && $this->stringOption('confirm-delete') !== null
        ) {
            throw new InvalidArgumentException('--confirm-delete can only be combined with --delete.');
        }
    }

    private function requireFingerprint(string $option, string $fingerprint): void
    {
        $provided = $this->stringOption($option);

        if ($provided === null) {
            if (! $this->input->isInteractive()) {
                throw new InvalidArgumentException(
                    "--{$option}={$fingerprint} is required in non-interactive mode.",
                );
            }

            $answer = $this->ask(
                $option === 'confirm'
                    ? 'Type the plan fingerprint to execute this compaction'
                    : 'Type the plan fingerprint again to permanently delete source migrations',
            );
            $provided = is_string($answer) ? $answer : '';
        }

        if (! hash_equals($fingerprint, $provided)) {
            throw new InvalidArgumentException(
                "--{$option} does not match the current plan fingerprint; nothing was changed.",
            );
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("--{$name} must contain a value.");
        }

        return $value;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function renderScope(CompactionPlan $plan): void
    {
        $summary = $plan->summary();
        $schema = $summary['schema'];

        $this->components->info('Migrafold compaction plan is valid. No changes have been made yet.');
        $this->line("Plan fingerprint: {$summary['plan_fingerprint']}");
        $this->line("Database: {$schema['driver']}");
        $this->line("Replay verification: {$summary['verification']['mode']}");
        $this->line("Tables: {$schema['tables']}");
        $this->line("Source disposition: {$summary['source_disposition']}");
        $this->line("Migration table: {$summary['records']['table']}");
        $this->line('Source migrations: '.count($summary['records']['retire']));
        $this->line('Baseline migrations: '.count($summary['records']['activate']));

        foreach ($summary['owners'] as $owner) {
            $this->line("Owner {$owner['id']}: {$owner['directory']}");
        }
    }

    private function renderResult(CompactionExecutionResult $result): void
    {
        if ($result->clean()) {
            $this->components->info('Migrafold compaction completed successfully.');
        } else {
            $this->warn('Migrafold compaction committed with cleanup warnings.');
        }

        $this->line('Published files: '.count($result->outputPaths));
        $this->line("Retired sources: {$result->retiredSources}");
        $this->line('Activated records: '.count($result->activation->baselines));
        $this->line('Migration batch: '.($result->activation->batch ?? 'unknown'));

        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }
    }
}
