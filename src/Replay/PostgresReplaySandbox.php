<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Illuminate\Database\PostgresConnection;

final readonly class PostgresReplaySandbox
{
    public function __construct(
        public string $connectionName,
        public PostgresConnection $connection,
        public string $database,
        public string $token,
        public string $label,
        public string $serverIdentity,
    ) {}
}
