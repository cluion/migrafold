<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Verification\Exception;

use RuntimeException;

final class ManifestVerificationFailed extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("MGF-VERIFY-001: {$reason}");
    }
}
