<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Disposition;

final readonly class ProtectedBaselineFile
{
    public function __construct(
        public string $ownerId,
        public string $migrationDirectory,
        public string $path,
        public string $sha256,
    ) {}
}
