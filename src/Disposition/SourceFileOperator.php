<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Disposition;

interface SourceFileOperator
{
    public function link(string $source, string $target): void;

    public function unlink(string $path): void;
}
