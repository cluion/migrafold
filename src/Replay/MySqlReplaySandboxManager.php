<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use JsonException;
use Throwable;

final readonly class MySqlReplaySandboxManager
{
    public const DATABASE_PREFIX = 'migrafold_replay_';

    public const MARKER_TABLE = 'migrafold_sandbox_owner';

    public function __construct(
        private DatabaseManager $databases,
        private Connection $source,
    ) {}

    public function create(string $label): MySqlReplaySandbox
    {
        $source = $this->sourceConnection();
        $this->assertLabel($label);
        $token = bin2hex(random_bytes(12));
        $database = self::DATABASE_PREFIX.$token.'_'.$label;
        $connectionName = 'migrafold-replay-'.$token.'-'.$label;

        if ($this->databaseExists($database)) {
            throw ReplayVerificationFailed::because(
                "sandbox database [{$database}] already exists and was not modified.",
            );
        }

        [$characterSet, $collation] = $this->databaseDefaults($source->getDatabaseName());
        $serverIdentity = $this->serverIdentity($source);
        $databaseIdentifier = $this->quoteIdentifier($database);
        $markerIdentifier = $this->quoteIdentifier(self::MARKER_TABLE);
        $marked = false;

        try {
            $source->statement(
                "create database {$databaseIdentifier} character set {$characterSet} collate {$collation}",
            );
            $source->statement(<<<SQL
create table {$databaseIdentifier}.{$markerIdentifier} (
    token varchar(64) not null,
    label varchar(16) not null,
    server_identity varchar(512) not null,
    primary key (token)
) engine = InnoDB
SQL);
            $source->insert(
                "insert into {$databaseIdentifier}.{$markerIdentifier} (token, label, server_identity) values (?, ?, ?)",
                [$token, $label, $serverIdentity],
            );
            $marked = true;
            $resolved = $this->databases->connectUsing(
                $connectionName,
                $this->sandboxConfig($database),
                true,
            );

            if (! $resolved instanceof MySqlConnection
                || $resolved->getDriverName() !== $source->getDriverName()
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

            return new MySqlReplaySandbox(
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
            }

            $reason = "sandbox database [{$database}] could not be created safely";

            if ($cleanupFailure !== null) {
                $reason .= '; ownership-verified cleanup also failed';
            }

            throw ReplayVerificationFailed::because($reason.'.', $exception);
        }
    }

    public function destroy(MySqlReplaySandbox $sandbox): void
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

            $databaseIdentifier = $this->quoteIdentifier($database);
            $markerIdentifier = $this->quoteIdentifier(self::MARKER_TABLE);
            $markers = $source->select(
                "select token, label, server_identity from {$databaseIdentifier}.{$markerIdentifier}",
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
            $source->statement("drop database {$databaseIdentifier}");

            if ($this->databaseExists($database)) {
                throw ReplayVerificationFailed::because(
                    "sandbox database [{$database}] still exists after cleanup.",
                );
            }
        } catch (ReplayVerificationFailed $exception) {
            $this->databases->purge($connectionName);

            throw $exception;
        } catch (Throwable $exception) {
            $this->databases->purge($connectionName);

            throw ReplayVerificationFailed::because(
                "sandbox database [{$database}] ownership could not be verified for cleanup.",
                $exception,
            );
        }
    }

    private function sourceConnection(): MySqlConnection
    {
        if (! $this->source instanceof MySqlConnection
            || ! in_array($this->source->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw ReplayVerificationFailed::because(
                "sandbox source driver [{$this->source->getDriverName()}] is not MySQL or MariaDB.",
            );
        }

        if ($this->source->transactionLevel() !== 0) {
            throw ReplayVerificationFailed::because(
                'sandbox databases cannot be created while the source connection has an active transaction.',
            );
        }

        if ($this->source->getTablePrefix() !== '') {
            throw ReplayVerificationFailed::because(
                'MySQL/MariaDB replay does not support a non-empty table prefix.',
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

    /** @return array{0: string, 1: string} */
    private function databaseDefaults(string $database): array
    {
        $rows = $this->source->select(<<<'SQL'
select default_character_set_name as character_set, default_collation_name as collation_name
from information_schema.schemata
where schema_name = ?
SQL, [$database]);
        $metadata = count($rows) === 1 ? array_change_key_case((array) $rows[0], CASE_LOWER) : [];
        $characterSet = $metadata['character_set'] ?? null;
        $collation = $metadata['collation_name'] ?? null;

        if (! is_string($characterSet)
            || ! is_string($collation)
            || preg_match('/\A[A-Za-z0-9_]+\z/', $characterSet) !== 1
            || preg_match('/\A[A-Za-z0-9_]+\z/', $collation) !== 1) {
            throw ReplayVerificationFailed::because(
                "source database [{$database}] has unsafe or unavailable character defaults.",
            );
        }

        return [$characterSet, $collation];
    }

    private function databaseExists(string $database): bool
    {
        return $this->source->select(
            'select schema_name from information_schema.schemata where schema_name = ?',
            [$database],
        ) !== [];
    }

    /** @return array<string, mixed> */
    private function sandboxConfig(string $database): array
    {
        $config = [
            'driver' => $this->source->getDriverName(),
            'database' => $database,
            'prefix' => '',
        ];

        foreach ([
            'host',
            'port',
            'unix_socket',
            'username',
            'password',
            'charset',
            'collation',
            'prefix_indexes',
            'strict',
            'engine',
            'isolation_level',
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
    private function serverIdentity(MySqlConnection $connection): string
    {
        $rows = $connection->select(<<<'SQL'
select @@hostname as hostname, @@port as port, @@server_id as server_id,
       @@version as version, @@version_comment as version_comment
SQL);
        $identity = count($rows) === 1 ? array_change_key_case((array) $rows[0], CASE_LOWER) : [];

        foreach (['hostname', 'port', 'server_id', 'version', 'version_comment'] as $key) {
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

        return '`'.$identifier.'`';
    }
}
