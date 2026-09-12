<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Replay\Exception\ReplayVerificationFailed;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;

final readonly class ReplayVerifierResolver
{
    public function __construct(
        private DatabaseManager $databases,
        private Filesystem $files,
    ) {}

    public function resolve(Connection $connection): MigrationReplayVerifier
    {
        return match ($connection->getDriverName()) {
            'sqlite' => new SqliteReplayVerifier($this->databases, $this->files),
            default => throw ReplayVerificationFailed::because(
                "same-engine replay is not implemented for [{$connection->getDriverName()}].",
            ),
        };
    }
}
