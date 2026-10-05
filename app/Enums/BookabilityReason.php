<?php

declare(strict_types=1);

namespace App\Enums;

enum BookabilityReason: string
{
    case SoldOut = 'SOLD_OUT';
    case NoSingleRoom = 'NO_SINGLE_ROOM';
}
