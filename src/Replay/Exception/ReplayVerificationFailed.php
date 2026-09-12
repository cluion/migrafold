<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Replay\Exception;

use RuntimeException;
use Throwable;

final class ReplayVerificationFailed extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            "MGF-REPLAY-001: Migration replay verification failed: {$reason}",
            previous: $previous,
        );
    }
}
