<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders()
    ->withExceptions(static function (Exceptions $exceptions): void {
        // Register Laravel's standard exception handler for package discovery
        // and CLI diagnostics in this intentionally minimal consumer app.
    })
    ->create();
