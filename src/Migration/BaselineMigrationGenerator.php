<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Migration;

use Cluion\Migrafold\Migration\Exception\UnsupportedMigrationGeneration;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;

final readonly class BaselineMigrationGenerator
{
    public function __construct(
        private TableDependencySorter $sorter = new TableDependencySorter(),
        private TableMigrationRenderer $renderer = new TableMigrationRenderer(),
    ) {}

    /** @return list<GeneratedMigration> */
    public function generate(SchemaSnapshot $snapshot, string $date): array
    {
        if (preg_match('/^\d{4}_\d{2}_\d{2}$/', $date) !== 1) {
            throw UnsupportedMigrationGeneration::forSchema(
                "generation date [{$date}] must use YYYY_MM_DD.",
            );
        }

        $migrations = [];
        $filenames = [];

        foreach ($this->sorter->sort($snapshot->tables) as $offset => $table) {
            $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $table->name), '_'));

            if ($slug === '') {
                throw UnsupportedMigrationGeneration::forSchema(
                    "table [{$table->name}] cannot be converted to a migration filename.",
                );
            }

            $filename = sprintf(
                '%s_%06d_create_%s_baseline.php',
                $date,
                $offset + 1,
                $slug,
            );

            if (isset($filenames[strtolower($filename)])) {
                throw UnsupportedMigrationGeneration::forSchema(
                    "table [{$table->name}] produces a duplicate migration filename [{$filename}].",
                );
            }

            $filenames[strtolower($filename)] = true;
            $migrations[] = new GeneratedMigration(
                filename: $filename,
                table: $table->name,
                contents: $this->renderer->render($table, $snapshot->driver),
            );
        }

        return $migrations;
    }
}
