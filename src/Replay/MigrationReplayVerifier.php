<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay;

use Cluion\Migrafold\Discovery\MigrationCatalog;

interface MigrationReplayVerifier
{
    public function verify(
        MigrationCatalog $catalog,
        string $date,
        string $migrationTable = 'migrations',
    ): ReplayVerificationResult;
}
