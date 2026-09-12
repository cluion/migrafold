<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Activation;

use Cluion\Migrafold\Activation\Exception\MigrationRecordActivationFailed;
use Cluion\Migrafold\Discovery\MigrationCatalog;
use Cluion\Migrafold\Disposition\PlannedSourceDisposition;
use Cluion\Migrafold\Disposition\ProtectedBaselineFile;
use Cluion\Migrafold\Disposition\SourceDispositionPlan;
use Cluion\Migrafold\Output\OwnerAwareOutputPlan;

final class MigrationRecordActivationPlanner
{
    public function plan(
        MigrationCatalog $catalog,
        OwnerAwareOutputPlan $output,
        SourceDispositionPlan $disposition,
    ): MigrationRecordActivationPlan {
        $sources = [];

        foreach ($disposition->items as $item) {
            $key = strtolower($this->normalizePath($item->source));

            if (isset($sources[$key])) {
                throw MigrationRecordActivationFailed::because(
                    "disposed source [{$item->relativeSource}] is duplicated.",
                );
            }

            $sources[$key] = $item;
        }

        $retired = [];

        foreach ($catalog->migrations as $migration) {
            $key = strtolower($this->normalizePath($migration->absolutePath));
            $source = $sources[$key] ?? null;

            if (
                ! $source instanceof PlannedSourceDisposition
                || strcasecmp($source->ownerId, $migration->ownerId) !== 0
                || $source->sha256 !== $migration->source->sha256
                || $source->relativeSource !== $migration->source->path
            ) {
                throw MigrationRecordActivationFailed::because(
                    "migration [{$migration->name}] is not covered by the source disposition plan.",
                );
            }

            unset($sources[$key]);
            $retired[] = new RetiredMigrationRecord($migration->name, $source);
        }

        if ($sources !== [] || $retired === []) {
            throw MigrationRecordActivationFailed::because(
                'source disposition scope does not exactly match the discovered migrations.',
            );
        }

        $protected = [];

        foreach ($disposition->protectedFiles as $file) {
            $key = strtolower($this->normalizePath($file->path));

            if (isset($protected[$key])) {
                throw MigrationRecordActivationFailed::because(
                    "protected baseline [{$file->path}] is duplicated.",
                );
            }

            $protected[$key] = $file;
        }

        $baselines = [];
        $expectedProtected = [];

        foreach ($output->owners as $owner) {
            foreach ($owner->output->files() as $file) {
                $path = $owner->output->directory.DIRECTORY_SEPARATOR.$file->filename;
                $key = strtolower($this->normalizePath($path));
                $protectedFile = $protected[$key] ?? null;

                if (
                    ! $protectedFile instanceof ProtectedBaselineFile
                    || strcasecmp($protectedFile->ownerId, $owner->ownerId) !== 0
                    || $protectedFile->sha256 !== $file->sha256
                ) {
                    throw MigrationRecordActivationFailed::because(
                        "baseline output [{$path}] is not protected by the source disposition plan.",
                    );
                }

                $expectedProtected[$key] = true;

                if ($file->table !== null) {
                    $baselines[] = new BaselineMigrationRecord(
                        substr($file->filename, 0, -4),
                        $protectedFile,
                    );
                }
            }
        }

        if (count($expectedProtected) !== count($protected) || $baselines === []) {
            throw MigrationRecordActivationFailed::because(
                'protected baseline scope does not exactly match the generated output.',
            );
        }

        usort(
            $retired,
            static fn (RetiredMigrationRecord $left, RetiredMigrationRecord $right): int => strcmp(
                $left->name,
                $right->name,
            ),
        );
        usort(
            $baselines,
            static fn (BaselineMigrationRecord $left, BaselineMigrationRecord $right): int => strcmp(
                $left->name,
                $right->name,
            ),
        );

        $this->assertUniqueNames($retired, $baselines);

        return new MigrationRecordActivationPlan(
            $disposition->projectRoot,
            $disposition->mode,
            $retired,
            $baselines,
            array_values($protected),
        );
    }

    /**
     * @param list<RetiredMigrationRecord> $retired
     * @param list<BaselineMigrationRecord> $baselines
     */
    private function assertUniqueNames(array $retired, array $baselines): void
    {
        $names = [];

        foreach ([...$retired, ...$baselines] as $record) {
            $key = strtolower($record->name);

            if (isset($names[$key])) {
                throw MigrationRecordActivationFailed::because(
                    "migration record [{$record->name}] overlaps [{$names[$key]}].",
                );
            }

            $names[$key] = $record->name;
        }
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/\\'));
    }
}
