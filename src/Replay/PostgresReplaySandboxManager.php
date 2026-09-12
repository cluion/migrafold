<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\PostgresConnection;
use JsonException;
use Throwable;

final readonly class PostgresReplaySandboxManager
{
    public const DATABASE_PREFIX = 'migrafold_replay_';

    public const MARKER_TABLE = 'migrafold_sandbox_owner';

    public function __construct(
        private DatabaseManager $databases,
        private Connection $source,
    ) {}

    public function create(string $label): PostgresReplaySandbox
    {
        $source = $this->sourceConnection();
        $this->assertLabel($label);
        $token = bin2hex(random_bytes(12));
        $database = self::DATABASE_PREFIX.$token.'_'.$label;
        $connectionName = 'migrafold-postgres-replay-'.$token.'-'.$label;

        if ($this->databaseExists($database)) {
            throw ReplayVerificationFailed::because(
                "sandbox database [{$database}] already exists and was not modified.",
            );
        }

        $serverIdentity = $this->serverIdentity($source);
        $databaseIdentifier = $this->quoteIdentifier($database);
        $marked = false;

        try {
            $source->statement("create database {$databaseIdentifier}");
            $resolved = $this->databases->connectUsing(
                $connectionName,
                $this->sandboxConfig($database),
                true,
            );

            if (! $resolved instanceof PostgresConnection
                || $resolved->getDriverName() !== 'pgsql'
                || $resolved->getDatabaseName() !== $database) {
                throw ReplayVerificationFailed::because(
                    "sandbox database [{$database}] did not resolve to the expected engine and identity.",
                );
            }

            if (! hash_equals($serverIdentity, $this->serverIdentity($resolved))) {
                throw ReplayVerificationFailed::because(
                    "sandbox database [{$database}] resolved to a different server.",
                );
            }

            $markerIdentifier = $this->quoteIdentifier(self::MARKER_TABLE);
            $resolved->statement(<<<SQL
create table {$markerIdentifier} (
    token varchar(64) not null primary key,
    label varchar(16) not null,
    server_identity text not null
)
SQL);
            $resolved->insert(
                "insert into {$markerIdentifier} (token, label, server_identity) values (?, ?, ?)",
                [$token, $label, $serverIdentity],
            );
            $marked = true;

            return new PostgresReplaySandbox(
                $connectionName,
                $resolved,
                $database,
                $token,
                $label,
                $serverIdentity,
            );
        } catch (Throwable $exception) {
            $cleanupFailure = null;

            if ($marked) {
                try {
                    $this->destroyDatabase(
                        $database,
                        $token,
                        $label,
                        $serverIdentity,
                        $connectionName,
                    );
                } catch (Throwable $cleanupException) {
                    $cleanupFailure = $cleanupException;
                }
            } else {
                $this->databases->purge($connectionName);
            }

            $reason = "sandbox database [{$database}] could not be created safely";

            if ($cleanupFailure !== null) {
                $reason .= '; ownership-verified cleanup also failed';
            }

            throw ReplayVerificationFailed::because($reason.'.', $exception);
        }
    }

    public function destroy(PostgresReplaySandbox $sandbox): void
    {
        $this->destroyDatabase(
            $sandbox->database,
            $sandbox->token,
            $sandbox->label,
            $sandbox->serverIdentity,
            $sandbox->connectionName,
        );
    }

    private function destroyDatabase(
        string $database,
        string $token,
        string $label,
        string $serverIdentity,
        string $connectionName,
    ): void {
        try {
            $this->assertLabel($label);
            $expected = self::DATABASE_PREFIX.$token.'_'.$label;

            if (preg_match('/\A[a-f0-9]{24}\z/', $token) !== 1 || ! hash_equals($expected, $database)) {
                throw ReplayVerificationFailed::because(
                    'sandbox database name does not match its cleanup token and label.',
                );
            }

            $source = $this->sourceConnection();

            if (! hash_equals($serverIdentity, $this->serverIdentity($source))) {
                throw ReplayVerificationFailed::because(
                    "sandbox database [{$database}] is not on the recorded server.",
                );
            }

            $sandbox = $this->databases->connection($connectionName);

            if (! $sandbox instanceof PostgresConnection
                || $sandbox->getDatabaseName() !== $database
                || ! hash_equals($serverIdentity, $this->serverIdentity($sandbox))) {
                throw ReplayVerificationFailed::because(
                    "sandbox database [{$database}] connection identity could not be verified.",
                );
            }

            $markerIdentifier = $this->quoteIdentifier(self::MARKER_TABLE);
            $markers = $sandbox->select(
                "select token, label, server_identity from {$markerIdentifier}",
            );
            $marker = count($markers) === 1 ? (array) $markers[0] : [];

            if (($marker['token'] ?? null) !== $token
                || ($marker['label'] ?? null) !== $label
                || ($marker['server_identity'] ?? null) !== $serverIdentity) {
                throw ReplayVerificationFailed::because(
                    "sandbox database [{$database}] ownership marker does not match cleanup scope.",
                );
            }

            $this->databases->purge($connectionName);
            $source->statement('drop database '.$this->quoteIdentifier($database));

            if ($this->databaseExists($database)) {
                throw ReplayVerificationFailed::because(
                    "sandbox database [{$database}] still exists after cleanup.",
                );
            }
        } catch (ReplayVerificationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->databases->purge($connectionName);

            throw ReplayVerificationFailed::because(
                "sandbox database [{$database}] ownership could not be verified for cleanup.",
                $exception,
            );
        }
    }

    private function sourceConnection(): PostgresConnection
    {
        if (! $this->source instanceof PostgresConnection || $this->source->getDriverName() !== 'pgsql') {
            throw ReplayVerificationFailed::because(
                "sandbox source driver [{$this->source->getDriverName()}] is not PostgreSQL.",
            );
        }

        if ($this->source->transactionLevel() !== 0) {
            throw ReplayVerificationFailed::because(
                'sandbox databases cannot be created while the source connection has an active transaction.',
            );
        }

        if ($this->source->getTablePrefix() !== '') {
            throw ReplayVerificationFailed::because(
                'PostgreSQL replay does not support a non-empty table prefix.',
            );
        }

        if ($this->source->getSchemaBuilder()->getCurrentSchemaListing() !== ['public']) {
            throw ReplayVerificationFailed::because(
                'PostgreSQL replay requires an exact [public] search path.',
            );
        }

        $database = $this->source->getDatabaseName();

        if ($database === '' || str_starts_with(strtolower($database), self::DATABASE_PREFIX)) {
            throw ReplayVerificationFailed::because(
                "source database [{$database}] is not a valid non-sandbox identity.",
            );
        }

        return $this->source;
    }

    private function databaseExists(string $database): bool
    {
        return $this->source->select(
            'select datname from pg_database where datname = ?',
            [$database],
        ) !== [];
    }

    /** @return array<string, mixed> */
    private function sandboxConfig(string $database): array
    {
        $config = [
            'driver' => 'pgsql',
            'database' => $database,
            'prefix' => '',
            'search_path' => 'public',
        ];

        foreach ([
            'host',
            'port',
            'username',
            'password',
            'charset',
            'sslmode',
            'channel_binding',
            'application_name',
            'options',
        ] as $key) {
            $value = $this->source->getConfig($key);

            if ($value !== null) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    /** @throws JsonException */
    private function serverIdentity(PostgresConnection $connection): string
    {
        $rows = $connection->select(<<<'SQL'
select
    system_identifier::text as system_identifier,
    current_setting('server_version_num') as server_version_num,
    coalesce(inet_server_addr()::text, 'local') as server_address,
    coalesce(inet_server_port(), 0) as server_port
from pg_control_system()
SQL);
        $identity = count($rows) === 1 ? array_change_key_case((array) $rows[0], CASE_LOWER) : [];

        foreach (['system_identifier', 'server_version_num', 'server_address', 'server_port'] as $key) {
            if (! isset($identity[$key]) || (! is_string($identity[$key]) && ! is_int($identity[$key]))) {
                throw ReplayVerificationFailed::because(
                    "database server identity field [{$key}] is unavailable.",
                );
            }
        }

        return json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function assertLabel(string $label): void
    {
        if (! in_array($label, ['source', 'baseline'], true)) {
            throw ReplayVerificationFailed::because("sandbox label [{$label}] is invalid.");
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier) !== 1) {
            throw ReplayVerificationFailed::because("sandbox identifier [{$identifier}] is unsafe.");
        }

        return '"'.$identifier.'"';
    }
}
