<?php

declare(strict_types=1);

namespace Cluion\Migrafold;

use Cluion\Migrafold\Console\CompactCommand;
use Cluion\Migrafold\Console\PlanCommand;
use Illuminate\Support\ServiceProvider;

final class MigrafoldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Concrete planning services are resolved through Laravel's container.
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CompactCommand::class,
                PlanCommand::class,
            ]);
        }
    }
}
