<?php

declare(strict_types=1);

namespace App\Support\Agencies;

use App\Enums\AgencyStatus;
use App\Enums\BookingStatus;
use App\Models\Agency;
use App\Models\Booking;
use Illuminate\Support\Collection;

final class AgencyBookingWindow
{
    /**
     * @param  Collection<int, Booking>  $bookings
     * @return Collection<int, Booking>
     */
    public static function inRange(Collection $bookings, ?string $from, ?string $to): Collection
    {
        return $bookings
            ->filter(function (Booking $booking) use ($from, $to): bool {
                $date = $booking->stay()->checkIn()->toDateString();

                if ($from !== null && $date < $from) {
                    return false;
                }

                if ($to !== null && $date > $to) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array{bookings_count: int, revenue: int, commission_accrued: int, held_bookings_count: int}
     */
    public static function stats(Collection $bookings): array
    {
        return [
            'bookings_count' => $bookings->count(),
            'revenue' => (int) $bookings->sum('total'),
            'commission_accrued' => (int) $bookings
                ->filter(fn (Booking $booking): bool => $booking->commission_approved && ! $booking->hasCommissionPayout())
                ->sum(fn (Booking $booking): int => $booking->commissionAmount()),
            'held_bookings_count' => $bookings
                ->filter(fn (Booking $booking): bool => $booking->status === BookingStatus::OnHoldAgency)
                ->count(),
        ];
    }

    public static function visible(Agency $agency, ?string $from, ?string $to): bool
    {
        if ($from === null && $to === null) {
            return true;
        }

        if ($agency->status === AgencyStatus::Pending) {
            return true;
        }

        $all = $agency->bookings;

        if ($all->isEmpty()) {
            return true;
        }

        return self::inRange($all, $from, $to)->isNotEmpty();
    }
}
