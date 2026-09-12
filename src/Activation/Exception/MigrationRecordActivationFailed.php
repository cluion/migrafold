<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Activation\Exception;

use RuntimeException;
use Throwable;

final class MigrationRecordActivationFailed extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self("MGF-ACTIVATION-001: {$reason}", 0, $previous);
    }
}
