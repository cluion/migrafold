<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Migration\Exception;

use RuntimeException;

final class UnsupportedMigrationGeneration extends RuntimeException
{
    public static function forSchema(string $reason): self
    {
        return new self("MGF-GENERATE-001: Cannot generate a safe baseline: {$reason}");
    }
}
