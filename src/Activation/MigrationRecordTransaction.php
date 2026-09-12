<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Activation;

use Closure;
use Illuminate\Database\Connection;

interface MigrationRecordTransaction
{
    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function run(Connection $connection, string $lockName, Closure $callback): mixed;
}
