<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Contracts;

use Cluion\Migrafold\Schema\Definition\SchemaSnapshot;
use Illuminate\Database\Connection;

interface SchemaInspector
{
    /**
     * @param list<string> $excludedTables
     */
    public function inspect(Connection $connection, array $excludedTables = ['migrations']): SchemaSnapshot;
}
