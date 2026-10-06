<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AvailabilityChanged;
use App\Events\BookingStatusChanged;
use App\Events\HoldExpired;
use App\Models\RoomType;
use App\Support\Stays\StayDates;
use App\Support\Waitlist\WaitlistOffers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class OfferWaitlistRooms implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private readonly WaitlistOffers $offers) {}

    public function handle(AvailabilityChanged|HoldExpired|BookingStatusChanged $event): void
    {
        if ($event instanceof AvailabilityChanged) {
            $this->offers->forOverlap($event->propertyId, $event->stay);

            return;
        }

        if ($event instanceof HoldExpired) {
            $this->offers->forClaim($event->claim);

            return;
        }

        if ($event->to->holdsInventory()) {
            return;
        }

        $booking = $event->booking;

        if ($booking->room_type_id === null) {
            return;
        }

        $type = RoomType::query()->find($booking->room_type_id);

        if (! $type instanceof RoomType) {
            return;
        }

        $this->offers->forOverlap(
            (int) $type->property_id,
            StayDates::of($booking->check_in, $booking->check_out),
        );
    }
}
