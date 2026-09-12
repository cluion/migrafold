<?php

declare(strict_types=1);

namespace Cluion\Migrafold;

use Cluion\Migrafold\Console\CompactCommand;
use Cluion\Migrafold\Console\PlanCommand;
use Cluion\Migrafold\Console\VerifyCommand;
use Illuminate\Support\ServiceProvider;

final class MigrafoldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/migrafold.php', 'migrafold');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/migrafold.php' => $this->app->configPath('migrafold.php'),
            ], 'migrafold-config');

            $this->commands([
                CompactCommand::class,
                PlanCommand::class,
                VerifyCommand::class,
            ]);
        }
    }
}
