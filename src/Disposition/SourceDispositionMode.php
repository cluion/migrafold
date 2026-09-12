<?php

declare(strict_types=1);

namespace Cluion\Migrafold\Disposition;

enum SourceDispositionMode: string
{
    case Archive = 'archive';
    case Delete = 'delete';
}
