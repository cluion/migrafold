<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Console;

use Cluion\Migrafold\Activation\MigrationTableNameResolver;
use Cluion\Migrafold\Planning\MigrationSourceAdapterFactory;
use Cluion\Migrafold\Verification\InstalledCompactionVerification;
use Cluion\Migrafold\Verification\InstalledCompactionVerifier;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use JsonException;
use Throwable;

final class VerifyCommand extends Command
{
    protected $signature = 'migrafold:verify
        {--connection= : Database connection to inspect}
        {--json : Emit the verification result as JSON}';

    protected $description = 'Verify published Migrafold manifests, migrations, records, and current schema without changes';

    public function __construct(
        private readonly Application $application,
        private readonly DatabaseManager $databases,
        private readonly Container $container,
        private readonly MigrationTableNameResolver $migrationTables,
        private readonly MigrationSourceAdapterFactory $adapters,
        private readonly InstalledCompactionVerifier $verifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $root = $this->application->basePath();
            $result = $this->verifier->verify(
                $root,
                $this->connection(),
                $this->adapters->forApplication($root, $this->container),
                $this->migrationTables->resolve(),
            );
            $this->render($result);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function connection(): Connection
    {
        $configured = $this->option('connection');

        if ($configured !== null && (! is_string($configured) || $configured === '')) {
            throw new \InvalidArgumentException('--connection must contain a value.');
        }

        return $this->databases->connection($configured);
    }

    /** @throws JsonException */
    private function render(InstalledCompactionVerification $result): void
    {
        $summary = $result->summary();

        if ($this->option('json') === true) {
            $this->line(json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return;
        }

        $this->components->info('Migrafold artifacts, records, and schema are verified. No changes were made.');
        $this->line("Database: {$summary['schema']['driver']}");
        $this->line("Schema fingerprint: {$summary['schema']['fingerprint']}");
        $this->line('Manifests: '.count($summary['manifests']));
        $this->line('Compacted migrations: '.count($summary['migrations']['compacted']));
        $this->line('Preserved migrations: '.count($summary['migrations']['preserved']));
        $this->line('Baseline migrations: '.count($summary['migrations']['baselines']));
        $this->line('Untracked migrations: '.count($summary['migrations']['untracked']));
        $this->line("Migration table: {$summary['records']['table']}");
        $this->line("Baseline batch: {$summary['records']['baseline_batch']}");
        $this->line('Preserved records present: '.count($summary['records']['preserved_recorded']));
        $this->line('Preserved records pending: '.count($summary['records']['preserved_pending']));
    }
}
