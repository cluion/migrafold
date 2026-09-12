<?php

declare(strict_types=1);

$config = require base_path('vendor/nwidart/laravel-modules/config/config.php');
$config['paths']['modules'] = base_path('Modules');
$config['paths']['generator']['migration']['path'] = 'database/migrations';
$config['activator'] = 'file';
$config['activators']['file']['statuses-file'] = base_path('modules_statuses.json');
$config['scan']['enabled'] = false;

return $config;
