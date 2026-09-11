<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

final readonly class OwnerOutputWriteResult
{
    /** @param list<string> $paths */
    public function __construct(
        public string $ownerId,
        public string $ownerName,
        public array $paths,
    ) {}
}
