<?php

declare(strict_types=1);

namespace App\Enums;

enum RoomTypeStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
