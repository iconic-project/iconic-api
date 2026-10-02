<?php

declare(strict_types=1);

namespace App\Support\Guests;

use App\Enums\BookingType;
use App\Support\Config\Documents\GuestsSettings;

final class GuestCapacity
{
    public static function max(BookingType $type, GuestsSettings $guests): int
    {
        return $type === BookingType::Charter
            ? $guests->maxPerProperty
            : $guests->maxPerCabin;
    }
}
