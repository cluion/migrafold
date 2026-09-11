<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Exception;

use RuntimeException;

final class UnsupportedDatabaseDriver extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return self::forInspector($driver, 'SQLite');
    }

    public static function forInspector(string $driver, string $inspector): self
    {
        return new self("MGF-SCHEMA-001: Database driver [{$driver}] is not supported by the {$inspector} inspector.");
    }
}
