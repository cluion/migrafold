<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Exception;

use RuntimeException;

final class UnsupportedDatabaseDriver extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return new self("MGF-SCHEMA-001: Database driver [{$driver}] is not supported by the SQLite inspector.");
    }
}
