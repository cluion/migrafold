<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Planning;

use Cluion\Migrafold\Activation\MigrationRecordActivationPlan;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Disposition\SourceDispositionPlan;
use Cluion\Migrafold\Output\OwnerAwareOutputPlan;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use JsonException;

final readonly class CompactionPlan
{
    public function __construct(
        public SchemaSnapshot $snapshot,
        public MigrationCatalog $catalog,
        public OwnerAwareOutputPlan $output,
        public SourceDispositionPlan $disposition,
        public MigrationRecordActivationPlan $activation,
        public string $migrationTable = 'migrations',
    ) {}

    /** @throws JsonException */
    public function fingerprint(): string
    {
        $sources = [];

        foreach ($this->catalog->migrations as $migration) {
            $sources[] = [
                'name' => $migration->name,
                'owner' => $migration->ownerId,
                'path' => $migration->source->path,
                'sha256' => $migration->source->sha256,
            ];
        }

        $outputs = [];

        foreach ($this->output->owners as $owner) {
            $files = [];

            foreach ($owner->output->files() as $file) {
                $files[] = [
                    'path' => $owner->output->directory.DIRECTORY_SEPARATOR.$file->filename,
                    'table' => $file->table,
                    'sha256' => $file->sha256,
                ];
            }

            $outputs[] = [
                'owner' => $owner->ownerId,
                'directory' => $owner->output->directory,
                'files' => $files,
            ];
        }

        $dispositions = [];

        foreach ($this->disposition->items as $item) {
            $dispositions[] = [
                'owner' => $item->ownerId,
                'source' => $item->source,
                'sha256' => $item->sha256,
                'destination' => $item->destination,
            ];
        }

        $payload = [
            'schema' => $this->snapshot->fingerprint(),
            'project_root' => $this->disposition->projectRoot,
            'migration_table' => $this->migrationTable,
            'sources' => $sources,
            'outputs' => $outputs,
            'disposition' => [
                'mode' => $this->disposition->mode->value,
                'items' => $dispositions,
            ],
            'records' => [
                'retire' => $this->activation->retiredNames(),
                'activate' => $this->activation->baselineNames(),
            ],
        ];

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @return array{
     *     plan_fingerprint: string,
     *     schema: array{driver: string, fingerprint: string, tables: int},
     *     source_disposition: string,
     *     owners: list<array{id: string, name: string, directory: string, sources: list<string>, baselines: list<string>}>,
     *     records: array{table: string, retire: list<string>, activate: list<string>}
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
            'plan_fingerprint' => $this->fingerprint(),
            'schema' => [
                'driver' => $this->snapshot->driver,
                'fingerprint' => $this->snapshot->fingerprint(),
                'tables' => count($this->snapshot->tables),
            ],
            'source_disposition' => $this->disposition->mode->value,
            'owners' => $owners,
            'records' => [
                'table' => $this->migrationTable,
                'retire' => $this->activation->retiredNames(),
                'activate' => $this->activation->baselineNames(),
            ],
        ];
    }
}
