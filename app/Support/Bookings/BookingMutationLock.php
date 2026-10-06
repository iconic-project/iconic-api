<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Models\Booking;

final class BookingMutationLock
{
    public const CHANGED = 'This booking changed — reload and try again.';

    /**
     * Lock the booking row. Stay inventory is claimed per night, so a
     * departure row is no longer part of the lock.
     */
    public static function acquire(Booking $booking): Booking
    {
        return Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
    }
}
