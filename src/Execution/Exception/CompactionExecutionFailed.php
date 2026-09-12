<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Execution\Exception;

use RuntimeException;
use Throwable;

final class CompactionExecutionFailed extends RuntimeException
{
    /** @param list<string> $recoveryErrors */
    public static function because(
        string $reason,
        array $recoveryErrors = [],
        ?Throwable $previous = null,
    ): self {
        $suffix = $recoveryErrors === []
            ? ''
            : ' Recovery was incomplete: '.implode(' ', $recoveryErrors);

        return new self("MGF-EXECUTION-001: {$reason}{$suffix}", 0, $previous);
    }
}
