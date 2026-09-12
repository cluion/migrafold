<?php

declare(strict_types=1);

return [
    'name' => 'Migrafold nWidart Laravel 12 Interoperability',
    'env' => env('APP_ENV', 'production'),
    'debug' => false,
    'url' => 'http://localhost',
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    'maintenance' => [
        'driver' => 'file',
    ],
];
