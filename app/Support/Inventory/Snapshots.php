<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Enums\EngineLabelCode;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Services\Inventory\Availability;
use Illuminate\Support\Collection;

final class Snapshots
{
    /**
     * @param  Collection<int, Departure>  $departures
     * @return array<int, DepartureSnapshot>
     */
    public static function attach(Collection $departures): array
    {
        $snapshots = app(Availability::class)->forDepartures($departures);

        foreach ($departures as $departure) {
            $departure->snapshot = $snapshots[$departure->id] ?? null;
        }

        return $snapshots;
    }

    /**
     * @param  Collection<int, Itinerary>  $itineraries
     */
    public static function attachLiveCounts(Collection $itineraries): void
    {
        if ($itineraries->isEmpty()) {
            return;
        }

        $departures = Departure::query()
            ->whereIn('itinerary_id', $itineraries->pluck('id'))
            ->with(['property.cabins', 'itinerary'])
            ->get();

        $snapshots = app(Availability::class)->forDepartures($departures);
        $hidden = [EngineLabelCode::NotShown->value, EngineLabelCode::Chartered->value];

        foreach ($itineraries as $itinerary) {
            $itinerary->live_departures_count = $departures
                ->where('itinerary_id', $itinerary->id)
                ->filter(function (Departure $departure) use ($snapshots, $hidden): bool {
                    $code = $snapshots[$departure->id]->engineLabel['code'] ?? null;

                    return ! in_array($code, $hidden, true);
                })
                ->count();
        }
    }
}
