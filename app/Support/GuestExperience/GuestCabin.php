<?php

declare(strict_types=1);

namespace App\Support\GuestExperience;

use App\Enums\BookingType;
use App\Models\Guest;

final class GuestCabin
{
    public static function label(Guest $guest): string
    {
        $guest->loadMissing('booking.cabin');
        $booking = $guest->booking;

        if ($booking->type === BookingType::Charter && $booking->room_id === null) {
            return 'Full property';
        }

        return $booking->cabin->label;
    }
}
