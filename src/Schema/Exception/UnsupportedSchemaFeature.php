<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Schema\Exception;

use RuntimeException;

final class UnsupportedSchemaFeature extends RuntimeException
{
    public static function detected(string $feature, string $identity): self
    {
        return self::detectedOn('SQLite', $feature, $identity);
    }

    public static function detectedOn(string $platform, string $feature, string $identity): self
    {
        return new self("MGF-SCHEMA-002: Unsupported {$platform} schema feature [{$feature}] detected at [{$identity}].");
    }
}
