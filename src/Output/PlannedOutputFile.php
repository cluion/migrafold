<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

final readonly class PlannedOutputFile
{
    public string $sha256;

    public function __construct(
        public string $filename,
        public string $contents,
        public ?string $table,
    ) {
        $this->sha256 = hash('sha256', $contents);
    }

    /** @return array{path: string, table: string, sha256: string} */
    public function manifestEntry(): array
    {
        return [
            'path' => $this->filename,
            'table' => (string) $this->table,
            'sha256' => $this->sha256,
        ];
    }
}
