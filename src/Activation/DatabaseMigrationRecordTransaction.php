<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Activation;

use Closure;
use Cluion\Migrafold\Activation\Exception\MigrationRecordActivationFailed;
use Illuminate\Database\Connection;
use Throwable;

final readonly class DatabaseMigrationRecordTransaction implements MigrationRecordTransaction
{
    public function __construct(private int $lockTimeoutSeconds = 5)
    {
        if ($lockTimeoutSeconds < 0) {
            throw MigrationRecordActivationFailed::because('lock timeout must not be negative.');
        }
    }

    public function run(Connection $connection, string $lockName, Closure $callback): mixed
    {
        if ($connection->transactionLevel() !== 0) {
            throw MigrationRecordActivationFailed::because(
                'migration-record activation cannot run inside an existing transaction.',
            );
        }

        return match ($connection->getDriverName()) {
            'sqlite' => $this->sqlite($connection, $callback),
            'mysql', 'mariadb' => $this->mysql($connection, $lockName, $callback),
            default => throw MigrationRecordActivationFailed::because(
                "database driver [{$connection->getDriverName()}] does not provide a supported activation lock.",
            ),
        };
    }

    private function sqlite(Connection $connection, Closure $callback): mixed
    {
        try {
            $connection->unprepared('BEGIN IMMEDIATE');
        } catch (Throwable $exception) {
            throw MigrationRecordActivationFailed::because(
                'could not acquire the SQLite migration-record write lock.',
                $exception,
            );
        }

        try {
            $result = $callback();
            $connection->getPdo()->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($connection->getPdo()->inTransaction()) {
                $connection->getPdo()->rollBack();
            }

            throw $exception;
        }
    }

    private function mysql(Connection $connection, string $lockName, Closure $callback): mixed
    {
        $name = 'migrafold:'.substr(hash('sha256', $lockName), 0, 48);
        $acquired = $connection->selectOne(
            'SELECT GET_LOCK(?, ?) AS acquired',
            [$name, $this->lockTimeoutSeconds],
            false,
        );

        if (! is_object($acquired) || ! $this->lockResultIsOne($acquired, 'acquired')) {
            throw MigrationRecordActivationFailed::because(
                'could not acquire the MySQL migration-record advisory lock.',
            );
        }

        $failure = null;
        $result = null;

        try {
            $connection->beginTransaction();
            $result = $callback();
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->transactionLevel() !== 0) {
                $connection->rollBack();
            }

            $failure = $exception;
        }

        try {
            $released = $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name], false);

            if (! is_object($released) || ! $this->lockResultIsOne($released, 'released')) {
                throw MigrationRecordActivationFailed::because(
                    'the MySQL migration-record advisory lock could not be released.',
                );
            }
        } catch (Throwable $releaseFailure) {
            if ($failure === null) {
                throw $releaseFailure;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    private function lockResultIsOne(object $result, string $property): bool
    {
        if (! property_exists($result, $property)) {
            return false;
        }

        $value = $result->{$property};

        return (is_int($value) || is_string($value)) && (string) $value === '1';
    }
}
