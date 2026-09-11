<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Discovery\Exception;

use RuntimeException;

final class MigrationDiscoveryFailed extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("MGF-DISCOVER-001: {$reason}");
    }
}
