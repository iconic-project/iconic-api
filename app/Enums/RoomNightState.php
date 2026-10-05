<?php

declare(strict_types=1);

namespace App\Enums;

enum RoomNightState: string
{
    case Free = 'FREE';
    case Held = 'HELD';
    case Sold = 'SOLD';
    case Blocked = 'BLOCKED';
}
