<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Activation;

use Cluion\Migrafold\Disposition\ProtectedBaselineFile;

final readonly class BaselineMigrationRecord
{
    public function __construct(
        public string $name,
        public ProtectedBaselineFile $file,
    ) {}
}
