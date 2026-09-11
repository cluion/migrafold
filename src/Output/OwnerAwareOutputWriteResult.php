<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

final readonly class OwnerAwareOutputWriteResult
{
    /** @param list<OwnerOutputWriteResult> $owners */
    public function __construct(
        public OutputMode $mode,
        public array $owners,
    ) {}

    public function written(): bool
    {
        return $this->mode === OutputMode::Write;
    }

    /** @return list<string> */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->owners as $owner) {
            array_push($paths, ...$owner->paths);
        }

        return $paths;
    }
}
