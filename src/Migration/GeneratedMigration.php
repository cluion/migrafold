<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Migration;

final readonly class GeneratedMigration
{
    public function __construct(
        public string $filename,
        public string $table,
        public string $contents,
    ) {}
}
