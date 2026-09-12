<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Planning;

use Cluion\Migrafold\Activation\MigrationRecordActivationPlanner;
use Cluion\Migrafold\Discovery\MigrationDiscoverer;
use Cluion\Migrafold\Discovery\MigrationSourceAdapter;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Disposition\SourceDispositionPlanner;
use Cluion\Migrafold\Migration\BaselineMigrationGenerator;
use Cluion\Migrafold\Output\OwnerAwareOutputPlanner;
use Cluion\Migrafold\Output\OwnerAwareOutputWriter;
use Illuminate\Database\Connection;

final readonly class CompactionPlanner
{
    public function __construct(
        private SchemaInspectorResolver $inspectors = new SchemaInspectorResolver(),
        private MigrationDiscoverer $discoverer = new MigrationDiscoverer(),
        private BaselineMigrationGenerator $generator = new BaselineMigrationGenerator(),
        private OwnerAwareOutputPlanner $outputPlanner = new OwnerAwareOutputPlanner(),
        private OwnerAwareOutputWriter $outputWriter = new OwnerAwareOutputWriter(),
        private SourceDispositionPlanner $dispositionPlanner = new SourceDispositionPlanner(),
        private MigrationRecordActivationPlanner $activationPlanner = new MigrationRecordActivationPlanner(),
    ) {}

    /** @param list<MigrationSourceAdapter> $adapters */
    public function plan(
        string $projectRoot,
        Connection $connection,
        array $adapters,
        string $date,
        SourceDispositionMode $disposition,
        ?string $archiveId,
        string $migrationTable = 'migrations',
    ): CompactionPlan {
        $catalog = $this->discoverer->discover($adapters);
        $snapshot = $this->inspectors->resolve($connection)->inspect(
            $connection,
            array_values(array_unique(['migrations', $migrationTable])),
        );
        $generated = $this->generator->generate($snapshot, $date);
        $output = $this->outputPlanner->plan($snapshot, $generated, $catalog);

        // This checks every owner directory and output collision without writing files.
        $this->outputWriter->execute($output);

        $sourceDisposition = $this->dispositionPlanner->plan(
            $projectRoot,
            $catalog,
            $output,
            $disposition,
            $archiveId,
        );
        $activation = $this->activationPlanner->plan($catalog, $output, $sourceDisposition);

        return new CompactionPlan(
            $snapshot,
            $catalog,
            $output,
            $sourceDisposition,
            $activation,
            $migrationTable,
        );
    }
}
