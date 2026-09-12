<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Disposition;

final readonly class PlannedSourceDisposition
{
    public function __construct(
        public string $ownerId,
        public string $migrationDirectory,
        public string $source,
        public string $relativeSource,
        public string $sha256,
        public ?string $destination,
    ) {}
}
