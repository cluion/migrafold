<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Exception;

use RuntimeException;

final class UnsupportedSchemaFeature extends RuntimeException
{
    public static function detected(string $feature, string $identity): self
    {
        return new self("MGF-SCHEMA-002: Unsupported SQLite schema feature [{$feature}] detected at [{$identity}].");
    }
}
