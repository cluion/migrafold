<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Contracts\SchemaInspector;
use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final readonly class MigrationSandboxRunner
{
    public function __construct(
        private DatabaseManager $databases,
        private Filesystem $files,
    ) {}

    /**
     * @param list<list<string>> $migrationGroups
     * @param list<string> $excludedTables
     */
    public function replay(
        Connection $connection,
        string $connectionName,
        array $migrationGroups,
        string $migrationTable,
        array $excludedTables,
        SchemaInspector $inspector,
        string $label,
    ): SchemaSnapshot {
        try {
            $repository = new DatabaseMigrationRepository($this->databases, $migrationTable);
            $migrator = new Migrator($repository, $this->databases, $this->files);

            /** @var list<string> $ran */
            [$ran, $snapshot] = $migrator->usingConnection(
                $connectionName,
                function () use (
                    $repository,
                    $migrator,
                    $migrationGroups,
                    $connection,
                    $excludedTables,
                    $inspector,
                ): array {
                    $repository->createRepository();
                    $ran = [];
                    $ranNames = [];

                    foreach ($migrationGroups as $migrationPaths) {
                        if ($migrationPaths === []) {
                            continue;
                        }

                        foreach ($migrator->run($migrationPaths) as $path) {
                            $name = pathinfo($path, PATHINFO_FILENAME);

                            if (isset($ranNames[strtolower($name)])) {
                                throw ReplayVerificationFailed::because(
                                    "sandbox migration [{$name}] appears in more than one replay group.",
                                );
                            }

                            $ranNames[strtolower($name)] = true;
                            $ran[] = $path;
                        }
                    }

                    return [$ran, $inspector->inspect($connection, $excludedTables)];
                },
            );
            $expectedMigrations = array_sum(array_map('count', $migrationGroups));

            if (count($ran) !== $expectedMigrations) {
                throw ReplayVerificationFailed::because(
                    "{$label} sandbox ran ".count($ran).' of '.$expectedMigrations.' migrations.',
                );
            }

            return $snapshot;
        } catch (ReplayVerificationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReplayVerificationFailed::because(
                $label.' sandbox replay raised ['.$exception::class.'].',
                $exception,
            );
        }
    }
}
