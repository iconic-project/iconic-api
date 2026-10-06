<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Models\Booking;

final class FrontDeskLock
{
    public static function acquire(Booking $booking): Booking
    {
        return BookingMutationLock::acquire($booking);
    }
}
