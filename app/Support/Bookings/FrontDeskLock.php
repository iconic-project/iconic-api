<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Models\Booking;

final class FrontDeskLock
{
    public static function acquire(Booking $booking): Booking
    {
        if ($booking->departure_id === null) {
            return Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
        }

        return BookingMutationLock::acquire($booking, (int) $booking->departure_id);
    }
}
