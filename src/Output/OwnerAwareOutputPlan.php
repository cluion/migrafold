<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

final readonly class OwnerAwareOutputPlan
{
    /** @param list<OwnerOutputPlan> $owners */
    public function __construct(public array $owners) {}

    /** @return list<string> */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->owners as $owner) {
            array_push($paths, ...$owner->output->paths());
        }

        return $paths;
    }
}
