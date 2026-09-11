<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

interface AtomicFilePublisher
{
    public function publish(string $staged, string $target): void;
}
