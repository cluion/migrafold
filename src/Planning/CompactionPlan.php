<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Planning;

use Cluion\Migrafold\Activation\MigrationRecordActivationPlan;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Disposition\SourceDispositionPlan;
use Cluion\Migrafold\Output\OwnerAwareOutputPlan;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;

final readonly class CompactionPlan
{
    public function __construct(
        public SchemaSnapshot $snapshot,
        public MigrationCatalog $catalog,
        public OwnerAwareOutputPlan $output,
        public SourceDispositionPlan $disposition,
        public MigrationRecordActivationPlan $activation,
    ) {}

    /**
     * @return array{
     *     schema: array{driver: string, fingerprint: string, tables: int},
     *     source_disposition: string,
     *     owners: list<array{id: string, name: string, directory: string, sources: list<string>, baselines: list<string>}>,
     *     records: array{retire: list<string>, activate: list<string>}
     * }
     */
    public function summary(): array
    {
        $owners = [];

        foreach ($this->output->owners as $owner) {
            $owners[] = [
                'id' => $owner->ownerId,
                'name' => $owner->ownerName,
                'directory' => $owner->output->directory,
                'sources' => array_map(
                    static fn ($migration): string => $migration->source->path,
                    $this->catalog->migrationsFor($owner->ownerId),
                ),
                'baselines' => array_map(
                    static fn ($migration): string => $migration->filename,
                    $owner->output->migrations,
                ),
            ];
        }

        return [
            'schema' => [
                'driver' => $this->snapshot->driver,
                'fingerprint' => $this->snapshot->fingerprint(),
                'tables' => count($this->snapshot->tables),
            ],
            'source_disposition' => $this->disposition->mode->value,
            'owners' => $owners,
            'records' => [
                'retire' => $this->activation->retiredNames(),
                'activate' => $this->activation->baselineNames(),
            ],
        ];
    }
}
