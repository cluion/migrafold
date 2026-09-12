<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Verification;

use Cluion\Migrafold\Verification\Exception\ManifestVerificationFailed;

final readonly class ManifestOutput
{
    public string $name;

    public function __construct(
        public string $filename,
        public string $table,
        public string $sha256,
    ) {
        if (basename($filename) !== $filename
            || preg_match('/\A\d{4}_\d{2}_\d{2}_\d{6}_create_[a-z0-9_]+_baseline\.php\z/', $filename) !== 1
            || $table === ''
            || str_contains($table, "\0")
            || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw ManifestVerificationFailed::because(
                "manifest output [{$filename}] has invalid path, table, or fingerprint metadata.",
            );
        }

        $this->name = substr($filename, 0, -4);
    }

    /** @return array{path: string, table: string, sha256: string} */
    public function toArray(): array
    {
        return [
            'path' => $this->filename,
            'table' => $this->table,
            'sha256' => $this->sha256,
        ];
    }
}
