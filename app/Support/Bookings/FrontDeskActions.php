<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Actions\Bookings\ModifyStay;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Stays\StayClock;
use App\Support\Stays\StayDates;
use RuntimeException;

/**
 * Action keys the actor may perform on this booking right now.
 * The panel renders these keys and does not re-decide legality.
 */
final class FrontDeskActions
{
    public function __construct(
        private StayClock $clock,
        private CurrentConfig $config,
    ) {}

    /**
     * @return list<'check_in'|'check_out'|'no_show'|'modify_stay'|'move_room'>
     */
    public function for(Booking $booking, User $actor): array
    {
        $stay = $this->stay($booking);

        if (! $stay instanceof StayDates) {
            return [];
        }

        $actions = [];

        if ($actor->can('frontDesk', $booking) && $this->canCheckIn($booking, $stay)) {
            $actions[] = 'check_in';
        }

        if ($actor->can('frontDesk', $booking) && $booking->status === BookingStatus::InHouse) {
            $actions[] = 'check_out';
        }

        if ($actor->can('frontDesk', $booking) && $this->canNoShow($booking, $stay)) {
            $actions[] = 'no_show';
        }

        if ($actor->can('update', $booking) && ModifyStay::allows($booking->status)) {
            $actions[] = 'modify_stay';
        }

        if ($actor->can('move', $booking)
            && ModifyStay::allows($booking->status)
            && $stay->checkIn()->toDateString() >= $this->clock->today()->toDateString()
        ) {
            $actions[] = 'move_room';
        }

        return $actions;
    }

    private function canCheckIn(Booking $booking, StayDates $stay): bool
    {
        if ($booking->checked_in_at !== null) {
            return false;
        }

        if (! $this->clock->isArrivalDayOrLater($stay)) {
            return false;
        }

        if ($booking->status === BookingStatus::FullyPaid) {
            return true;
        }

        return $booking->status === BookingStatus::Confirmed
            && ! $this->config->businessRules()->stay->checkInRequiresFullPayment;
    }

    private function canNoShow(Booking $booking, StayDates $stay): bool
    {
        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::FullyPaid], true)) {
            return false;
        }

        return $this->clock->isNoShowWindow($stay);
    }

    private function stay(Booking $booking): ?StayDates
    {
        try {
            return $booking->stay();
        } catch (RuntimeException) {
            return null;
        }
    }
}
