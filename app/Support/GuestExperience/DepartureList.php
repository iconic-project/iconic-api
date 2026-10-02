<?php

declare(strict_types=1);

namespace App\Support\GuestExperience;

use App\Enums\BookingStatus;
use App\Models\Departure;
use App\Models\Guest;
use App\Support\Manifests\ManifestRoster;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

final class DepartureList
{
    /**
     * Departures that have passengers on sold bookings, earliest first.
     *
     * @return Collection<int, array{departure_id: int, date: string, property: string, passengers: int}>
     */
    public static function withPassengers(): Collection
    {
        $statuses = array_map(
            fn (BookingStatus $status): string => $status->value,
            ManifestRoster::COUNTED,
        );

        /** @var Collection<int, int> $counts */
        $counts = Guest::query()
            ->join('bookings', function (JoinClause $join): void {
                $join->on('bookings.id', '=', 'guests.booking_id')
                    ->whereNull('bookings.deleted_at');
            })
            ->whereIn('bookings.status', $statuses)
            ->groupBy('bookings.departure_id')
            ->selectRaw('bookings.departure_id as departure_id, count(*) as passengers')
            ->pluck('passengers', 'departure_id')
            ->mapWithKeys(fn (mixed $count, mixed $id): array => [(int) $id => (int) $count]);

        if ($counts->isEmpty()) {
            return collect();
        }

        return Departure::query()
            ->with('property')
            ->whereIn('id', $counts->keys())
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function (Departure $departure) use ($counts): array {
                return [
                    'departure_id' => $departure->id,
                    'date' => $departure->date->toDateString(),
                    'property' => $departure->property->name,
                    'passengers' => $counts->get($departure->id, 0),
                ];
            })
            ->values();
    }
}
