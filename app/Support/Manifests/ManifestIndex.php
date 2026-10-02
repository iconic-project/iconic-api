<?php

declare(strict_types=1);

namespace App\Support\Manifests;

use App\Enums\BookingStatus;
use App\Models\Departure;
use App\Models\Manifest;
use App\Support\BusinessTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

final class ManifestIndex
{
    /**
     * @return Collection<int, ManifestRow>
     */
    public static function between(?string $from, ?string $to): Collection
    {
        $today = BusinessTime::now()->toDateString();
        $departures = self::departures($from, $to);
        $latest = self::latestVersions($departures->pluck('id')->all());

        return $departures->map(function (Departure $departure) use ($today, $latest): ManifestRow {
            $passengers = ManifestRoster::passengers($departure);
            $due = ManifestDue::forDeparture($departure, $passengers);
            $complete = $passengers->filter(fn (ManifestPassenger $passenger): bool => $passenger->complete())->count();

            return new ManifestRow(
                $departure,
                ManifestRoster::isCharter($passengers),
                $passengers->count(),
                $complete,
                $due,
                ManifestReadiness::label($passengers->count(), $complete, $today >= $due->dpng),
                $latest[$departure->id.'|DPNG'] ?? null,
                $latest[$departure->id.'|CAPTAIN'] ?? null,
            );
        })->values();
    }

    /**
     * @return Collection<int, Departure>
     */
    private static function departures(?string $from, ?string $to): Collection
    {
        $statuses = array_map(
            fn (BookingStatus $status): string => $status->value,
            ManifestRoster::COUNTED,
        );

        $query = Departure::query()
            ->with(['property', 'itinerary']);

        if ($from !== null) {
            $query->where('date', '>=', $from);
        }

        if ($to !== null) {
            $query->where('date', '<=', $to);
        }

        return $query
            ->whereExists(function (Builder $query) use ($statuses): void {
                $query->selectRaw('1')
                    ->from('bookings')
                    ->join('guests', 'guests.booking_id', '=', 'bookings.id')
                    ->whereColumn('bookings.departure_id', 'departures.id')
                    ->whereNull('bookings.deleted_at')
                    ->whereIn('bookings.status', $statuses);
            })
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $departureIds
     * @return array<string, Manifest>
     */
    private static function latestVersions(array $departureIds): array
    {
        if ($departureIds === []) {
            return [];
        }

        $latest = [];

        Manifest::query()
            ->with('generatedBy')
            ->whereIn('departure_id', $departureIds)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get()
            ->each(function (Manifest $manifest) use (&$latest): void {
                $key = $manifest->departure_id.'|'.$manifest->kind->value;

                if (! isset($latest[$key])) {
                    $latest[$key] = $manifest;
                }
            });

        return $latest;
    }
}
