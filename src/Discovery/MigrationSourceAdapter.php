<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Discovery;

interface MigrationSourceAdapter
{
    public function discover(): AdapterDiscovery;
}
