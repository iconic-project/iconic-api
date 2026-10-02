<?php

declare(strict_types=1);

namespace App\Enums;

enum RoomStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
