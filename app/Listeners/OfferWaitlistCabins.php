<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AvailabilityChanged;
use App\Events\BookingStatusChanged;
use App\Events\HoldExpired;
use App\Support\Inventory\DepartureNightClaims;
use App\Support\Stays\StayDates;
use App\Support\Waitlist\WaitlistOffers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class OfferWaitlistCabins implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private readonly WaitlistOffers $offers) {}

    public function handle(AvailabilityChanged|HoldExpired|BookingStatusChanged $event): void
    {
        if ($event instanceof AvailabilityChanged) {
            $this->offers->forDepartures(DepartureNightClaims::departureIds($event->propertyId, $event->stay));

            return;
        }

        if ($event instanceof HoldExpired) {
            $this->offers->forDepartures($this->idsForClaim($event));

            return;
        }

        if ($event->to->holdsInventory()) {
            return;
        }

        $this->offers->forDepartures([(int) $event->booking->departure_id]);
    }

    /**
     * @return list<int>
     */
    private function idsForClaim(HoldExpired $event): array
    {
        $claim = $event->claim;

        if ($claim->getAttribute('room_id') === null || $claim->getAttribute('night') === null) {
            return [];
        }

        $claim->loadMissing('room');
        $room = $claim->room;

        return DepartureNightClaims::departureIds(
            (int) $room->property_id,
            StayDates::forNights($claim->night, 1),
        );
    }
}
