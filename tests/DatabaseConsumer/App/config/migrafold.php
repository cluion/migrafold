<?php

declare(strict_types=1);

$config = require base_path('vendor/cluion/migrafold/config/migrafold.php');
$config['nwidart']['table_owners'] = [
    'products' => 'Inventory',
    'stock_movements' => 'Inventory',
    'warehouses' => 'Inventory',
];

return $config;
