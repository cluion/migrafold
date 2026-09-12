<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Disposition;

use Cluion\Migrafold\Disposition\Exception\SourceDispositionFailed;

final class NativeSourceFileOperator implements SourceFileOperator
{
    public function link(string $source, string $target): void
    {
        if (! @link($source, $target)) {
            throw SourceDispositionFailed::because(
                "file [{$source}] could not be linked to [{$target}] without overwriting.",
            );
        }
    }

    public function unlink(string $path): void
    {
        if (! @unlink($path)) {
            throw SourceDispositionFailed::because("file [{$path}] could not be removed.");
        }
    }
}
