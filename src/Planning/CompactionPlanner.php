<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Planning;

use Cluion\Migrafold\Activation\MigrationRecordActivationPlanner;
use Cluion\Migrafold\Analysis\MigrationCompactionScope;
use Cluion\Migrafold\Discovery\MigrationDiscoverer;
use Cluion\Migrafold\Discovery\MigrationSourceAdapter;
use Cluion\Migrafold\Disposition\SourceDispositionMode;
use Cluion\Migrafold\Disposition\SourceDispositionPlanner;
use Cluion\Migrafold\Output\OwnerAwareOutputPlanner;
use Cluion\Migrafold\Output\OwnerAwareOutputWriter;
use Cluion\Migrafold\Replay\ReplayVerifierResolver;
use Cluion\Migrafold\Replay\SchemaReplayComparator;
use Illuminate\Database\Connection;

final readonly class CompactionPlanner
{
    public function __construct(
        private ReplayVerifierResolver $replays,
        private SchemaInspectorResolver $inspectors = new SchemaInspectorResolver(),
        private MigrationDiscoverer $discoverer = new MigrationDiscoverer(),
        private OwnerAwareOutputPlanner $outputPlanner = new OwnerAwareOutputPlanner(),
        private OwnerAwareOutputWriter $outputWriter = new OwnerAwareOutputWriter(),
        private SourceDispositionPlanner $dispositionPlanner = new SourceDispositionPlanner(),
        private MigrationRecordActivationPlanner $activationPlanner = new MigrationRecordActivationPlanner(),
        private SchemaReplayComparator $comparator = new SchemaReplayComparator(),
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
        $replay = $this->replays->resolve($connection)->verify($catalog, $date, $migrationTable);
        $snapshot = $this->inspectors->resolve($connection)->inspect(
            $connection,
            array_values(array_unique(['migrations', $migrationTable])),
        );
        $this->comparator->assertEquivalent($replay->source, $snapshot, 'source replay', 'current database');
        $scope = new MigrationCompactionScope($catalog, $replay->analysis);
        $output = $this->outputPlanner->plan($snapshot, $replay->baselines, $scope->compacted);

        // This checks every owner directory and output collision without writing files.
        $this->outputWriter->execute($output);

        $sourceDisposition = $this->dispositionPlanner->plan(
            $projectRoot,
            $scope->compacted,
            $output,
            $disposition,
            $archiveId,
        );
        $activation = $this->activationPlanner->plan($scope->compacted, $output, $sourceDisposition);

        return new CompactionPlan(
            $snapshot,
            $catalog,
            $replay->analysis,
            $output,
            $sourceDisposition,
            $activation,
            $migrationTable,
        );
    }
}
