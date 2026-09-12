<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Illuminate\Database\MySqlConnection;

final readonly class MySqlReplaySandbox
{
    public function __construct(
        public string $connectionName,
        public MySqlConnection $connection,
        public string $database,
        public string $token,
        public string $label,
        public string $serverIdentity,
    ) {}
}
