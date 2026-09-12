<?php

declare(strict_types=1);

$config = require base_path('vendor/cluion/moduark/config/moduark.php');
$config['path'] = app_path('Modules');

return $config;
