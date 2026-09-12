<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Verification;

use Cluion\Migrafold\Output\SourceMigration;
use JsonException;

final readonly class InstalledCompactionManifest
{
    /** @var list<SourceMigration> */
    public array $sources;

    /** @var list<ManifestOutput> */
    public array $outputs;

    /** @var list<ManifestMigration> */
    public array $compacted;

    /** @var list<ManifestMigration> */
    public array $preserved;

    /**
     * @param list<SourceMigration> $sources
     * @param list<ManifestOutput> $outputs
     * @param list<ManifestMigration> $compacted
     * @param list<ManifestMigration> $preserved
     */
    public function __construct(
        public string $path,
        public string $directory,
        public string $ownerId,
        public string $ownerName,
        public string $schemaFormat,
        public string $driver,
        public string $schemaFingerprint,
        public int $sourceMigrations,
        public int $baselineMigrations,
        public int $preservedMigrations,
        array $sources,
        array $outputs,
        array $compacted,
        array $preserved,
    ) {
        $this->sources = $sources;
        $this->outputs = $outputs;
        $this->compacted = $compacted;
        $this->preserved = $preserved;
    }

    /** @throws JsonException */
    public function auditFingerprint(): string
    {
        return hash('sha256', json_encode([
            'schema_format' => $this->schemaFormat,
            'driver' => $this->driver,
            'schema_fingerprint' => $this->schemaFingerprint,
            'source_migrations' => $this->sourceMigrations,
            'baseline_migrations' => $this->baselineMigrations,
            'preserved_migrations' => $this->preservedMigrations,
            'compacted' => array_map(
                static fn (ManifestMigration $migration): array => $migration->toArray(),
                $this->compacted,
            ),
            'preserved' => array_map(
                static fn (ManifestMigration $migration): array => $migration->toArray(),
                $this->preserved,
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
