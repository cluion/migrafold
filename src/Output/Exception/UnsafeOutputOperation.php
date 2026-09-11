<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output\Exception;

use RuntimeException;

final class UnsafeOutputOperation extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("MGF-OUTPUT-001: {$reason}");
    }
}
