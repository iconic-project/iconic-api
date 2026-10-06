<?php

declare(strict_types=1);

namespace App\Support\Guests;

use App\Enums\BookingType;
use App\Enums\RoomTypeStatus;
use App\Models\RoomType;
use App\Support\Config\Documents\GuestsSettings;

final class GuestCapacity
{
    public static function max(BookingType $type, GuestsSettings $guests, ?RoomType $roomType = null): int
    {
        if ($type === BookingType::Charter) {
            return $guests->maxPerProperty;
        }

        if ($roomType instanceof RoomType) {
            return $roomType->max_occupancy;
        }

        $max = RoomType::query()
            ->where('status', RoomTypeStatus::Active)
            ->max('max_occupancy');

        return is_numeric($max) ? (int) $max : 0;
    }
}
