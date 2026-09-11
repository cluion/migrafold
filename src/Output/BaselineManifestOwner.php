<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Output;

use Cluion\Migrafold\Output\Exception\UnsafeOutputOperation;

final readonly class BaselineManifestOwner
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
        if (preg_match('/\A[a-z][a-z0-9.-]*:[A-Za-z][A-Za-z0-9_.-]*\z/', $id) !== 1) {
            throw UnsafeOutputOperation::because("manifest owner id [{$id}] is invalid.");
        }

        if ($name === '' || trim($name) !== $name) {
            throw UnsafeOutputOperation::because("manifest owner [{$id}] has an invalid name.");
        }
    }

    /** @return array{id: string, name: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
