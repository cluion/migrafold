<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Verification;

use Cluion\Migrafold\Verification\Exception\ManifestVerificationFailed;

final readonly class ManifestMigration
{
    public function __construct(
        public string $name,
        public string $ownerId,
        public string $path,
        public string $sha256,
        public string $classification,
        public string $action,
    ) {
        $portable = str_replace('\\', '/', $path);
        $segments = explode('/', $portable);
        $expectedClassifications = $action === 'compact'
            ? ['schema_only']
            : ['data_only', 'non_schema'];

        if ($name === ''
            || str_contains($name, "\0")
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || preg_match('/\A[a-z][a-z0-9.-]*:[A-Za-z][A-Za-z0-9_.-]*\z/', $ownerId) !== 1
            || $path === ''
            || $portable !== $path
            || str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:\//', $path) === 1
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || basename($path) !== $name.'.php'
            || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
            || ! in_array($action, ['compact', 'preserve'], true)
            || ! in_array($classification, $expectedClassifications, true)) {
            throw ManifestVerificationFailed::because(
                "manifest migration [{$name}] has invalid identity, path, fingerprint, or action metadata.",
            );
        }
    }

    /** @return array{name: string, owner: string, path: string, sha256: string, classification: string, action: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'owner' => $this->ownerId,
            'path' => $this->path,
            'sha256' => $this->sha256,
            'classification' => $this->classification,
            'action' => $this->action,
        ];
    }
}
