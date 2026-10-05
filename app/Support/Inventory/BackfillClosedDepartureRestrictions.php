<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Actions\Restrictions\SetStayRestrictions;
use App\Enums\DepartureStatus;
use App\Models\Departure;

/**
 * One-time copy of CLOSED and HIDDEN departures onto property-wide stop_sell.
 * Later status changes are not synced. CHARTER and ON_SALE are left alone.
 * The occupied nights are [check-in, check-out). The restriction range is inclusive,
 * so `to` is the last night, not the check-out date.
 */
final class BackfillClosedDepartureRestrictions
{
    public function __construct(
        private readonly SetStayRestrictions $set,
    ) {}

    public static function run(): int
    {
        return app(self::class)->backfill();
    }

    public function backfill(): int
    {
        $count = 0;

        Departure::query()
            ->with('itinerary')
            ->whereIn('status', [DepartureStatus::Closed, DepartureStatus::Hidden])
            ->orderBy('id')
            ->each(function (Departure $departure) use (&$count): void {
                $stay = $departure->stayDates();

                $this->set->handle([
                    'property_id' => $departure->property_id,
                    'room_type_ids' => [],
                    'from' => $stay->checkIn()->toDateString(),
                    'to' => $stay->lastNight()->toDateString(),
                    'stop_sell' => true,
                ]);

                $count++;
            });

        return $count;
    }
}
