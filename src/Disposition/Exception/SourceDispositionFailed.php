<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Disposition\Exception;

use RuntimeException;

final class SourceDispositionFailed extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("MGF-DISPOSITION-001: {$reason}");
    }
}
