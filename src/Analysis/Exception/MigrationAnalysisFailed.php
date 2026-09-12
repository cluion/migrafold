<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Analysis\Exception;

use RuntimeException;
use Throwable;

final class MigrationAnalysisFailed extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self("MGF-ANALYZE-001: {$reason}", previous: $previous);
    }
}
