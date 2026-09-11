<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

final readonly class BaselineOutputPlan
{
    public const MANIFEST_FILENAME = '.migrafold-manifest.json';

    /**
     * @param list<PlannedOutputFile> $migrations
     */
    public function __construct(
        public string $directory,
        public array $migrations,
        public string $manifestContents,
    ) {}

    /** @return list<PlannedOutputFile> */
    public function files(): array
    {
        return [
            ...$this->migrations,
            new PlannedOutputFile(self::MANIFEST_FILENAME, $this->manifestContents, null),
        ];
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(
            fn (PlannedOutputFile $file): string => $this->directory.DIRECTORY_SEPARATOR.$file->filename,
            $this->files(),
        );
    }
}
