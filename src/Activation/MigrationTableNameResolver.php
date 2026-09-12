<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Activation;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final readonly class MigrationTableNameResolver
{
    public function __construct(private Repository $config) {}

    public function resolve(): string
    {
        $configured = $this->config->get('database.migrations', 'migrations');

        if (is_array($configured)) {
            $configured = $configured['table'] ?? null;
        }

        if (
            ! is_string($configured)
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $configured) !== 1
        ) {
            throw new InvalidArgumentException('Laravel migration table configuration is invalid.');
        }

        return $configured;
    }
}
