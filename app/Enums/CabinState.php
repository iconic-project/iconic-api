<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * @deprecated Use RoomNightState. Same four values. PHP enums cannot share a case list, so this twin stays for yacht callers.
 */
enum CabinState: string
{
    case Free = 'FREE';
    case Held = 'HELD';
    case Sold = 'SOLD';
    case Blocked = 'BLOCKED';
}
