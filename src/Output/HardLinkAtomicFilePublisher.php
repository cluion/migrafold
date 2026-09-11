<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

use Cluion\Migrafold\Output\Exception\UnsafeOutputOperation;

final class HardLinkAtomicFilePublisher implements AtomicFilePublisher
{
    public function publish(string $staged, string $target): void
    {
        if (! @link($staged, $target)) {
            throw UnsafeOutputOperation::because(
                "output [{$target}] could not be published without overwriting an existing file.",
            );
        }
    }
}
