<?php

declare(strict_types=1);

namespace App\Enums;

enum ReleaseReason: string
{
    case Released = 'RELEASED';
    case Expired = 'EXPIRED';
    case Converted = 'CONVERTED';
    case Cancelled = 'CANCELLED';
    case Moved = 'MOVED';
    case NoShow = 'NO_SHOW';
}
