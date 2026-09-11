<?php

declare(strict_types=1);

namespace Cluion\Migrafold;

use Illuminate\Support\ServiceProvider;

final class MigrafoldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Public bindings are intentionally deferred until discovery contracts are accepted.
    }
}
