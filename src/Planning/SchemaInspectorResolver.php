<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Planning;

use Cluion\Migrafold\Contracts\SchemaInspector;
use Cluion\Migrafold\Schema\Exception\UnsupportedDatabaseDriver;
use Cluion\Migrafold\Schema\MySqlSchemaInspector;
use Cluion\Migrafold\Schema\PostgresSchemaInspector;
use Cluion\Migrafold\Schema\SqliteSchemaInspector;
use Illuminate\Database\Connection;

final readonly class SchemaInspectorResolver
{
    public function resolve(Connection $connection): SchemaInspector
    {
        return match ($connection->getDriverName()) {
            'sqlite' => new SqliteSchemaInspector(),
            'mysql', 'mariadb' => new MySqlSchemaInspector(),
            'pgsql' => new PostgresSchemaInspector(),
            default => throw UnsupportedDatabaseDriver::forDriver($connection->getDriverName()),
        };
    }
}
