<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Console;

use Cluion\Migrafold\Activation\MigrationTableNameResolver;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
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
use JsonException;
use Throwable;

final class PlanCommand extends Command
{
    protected $signature = 'migrafold:plan
        {--connection= : Database connection to inspect}
        {--date= : Baseline filename date in YYYY_MM_DD}
        {--delete : Preview permanent source deletion instead of archival}
        {--archive-id= : Safe archive identifier used by archive mode}
        {--json : Emit the plan as JSON}';

    protected $description = 'Preview a verified migration compaction plan without changing files or database records';

    public function __construct(
        private readonly Application $application,
        private readonly DatabaseManager $databases,
        private readonly Container $container,
        private readonly MigrationTableNameResolver $migrationTables,
        private readonly MigrationSourceAdapterFactory $adapters,
        private readonly CompactionPlanner $planner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $disposition = $this->option('delete') === true
                ? SourceDispositionMode::Delete
                : SourceDispositionMode::Archive;
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

            $this->render($plan);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function connection(): Connection
    {
        $name = $this->stringOption('connection');

        return $this->databases->connection($name);
    }

    private function archiveId(SourceDispositionMode $disposition): ?string
    {
        $configured = $this->stringOption('archive-id');

        if ($disposition === SourceDispositionMode::Delete) {
            if ($configured !== null) {
                throw new \InvalidArgumentException('--archive-id cannot be combined with --delete.');
            }

            return null;
        }

        return $configured ?? $this->now()->format('Ymd\THis\Z');
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("--{$name} must contain a value.");
        }

        return $value;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @throws JsonException */
    private function render(CompactionPlan $plan): void
    {
        $summary = $plan->summary();

        if ($this->option('json') === true) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return;
        }

        $schema = $summary['schema'];
        $this->components->info('Migrafold plan is valid. No files or database records were changed.');
        $this->line("Plan fingerprint: {$summary['plan_fingerprint']}");
        $this->line("Database: {$schema['driver']}");
        $this->line("Schema fingerprint: {$schema['fingerprint']}");
        $this->line("Tables: {$schema['tables']}");
        $this->line("Source disposition: {$summary['source_disposition']}");
        $this->line("Migration table: {$summary['records']['table']}");
        $this->line('Retired records: '.count($summary['records']['retire']));
        $this->line('Baseline records: '.count($summary['records']['activate']));

        foreach ($summary['owners'] as $owner) {
            $this->newLine();
            $this->line("Owner: {$owner['id']}");
            $this->line("Directory: {$owner['directory']}");
            $this->line('Sources: '.count($owner['sources']));
            $this->line('Baselines: '.count($owner['baselines']));
        }
    }
}
