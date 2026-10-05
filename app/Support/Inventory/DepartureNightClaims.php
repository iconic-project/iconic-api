<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\Booking;
use App\Models\Departure;
use App\Models\RoomNightClaim;
use App\Support\Stays\StayDates;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Yacht read model: one room-night claim stands for the cabin on a departure.
 * Live rows win over released ones. A released-only room still counts as history.
 */
final class DepartureNightClaims
{
    /**
     * @param  Collection<int, Departure>  $departures
     * @return array<int, Collection<int, RoomNightClaim>>
     */
    public static function byDeparture(Collection $departures): array
    {
        if ($departures->isEmpty()) {
            return [];
        }

        $models = $departures instanceof EloquentCollection
            ? $departures
            : new EloquentCollection($departures->all());

        $models->loadMissing('itinerary');

        /** @var EloquentCollection<int, Departure> $departures */
        $departures = $models;

        $propertyIds = $departures->pluck('property_id')->unique()->values()->all();
        $from = $departures->min(fn (Departure $departure): string => $departure->stayDates()->checkIn()->toDateString());
        $to = $departures->max(fn (Departure $departure): string => $departure->stayDates()->lastNight()->toDateString());

        $claims = RoomNightClaim::query()
            ->whereHas('room', fn ($query) => $query->whereIn('property_id', $propertyIds))
            ->whereDate('night', '>=', $from)
            ->whereDate('night', '<=', $to)
            ->with(['room.roomType', 'holder' => function (Relation $morph): void {
                if ($morph instanceof MorphTo) {
                    $morph->morphWith([
                        Booking::class => ['owner', 'bookingRequest'],
                    ]);
                }
            }])
            ->orderBy('id')
            ->get();

        $result = [];

        foreach ($departures as $departure) {
            $stay = $departure->stayDates();
            $checkIn = $stay->checkIn()->toDateString();
            $lastNight = $stay->lastNight()->toDateString();

            $rows = $claims->filter(function (RoomNightClaim $claim) use ($departure, $checkIn, $lastNight): bool {
                if ($claim->room->property_id !== $departure->property_id) {
                    return false;
                }

                $night = $claim->night->toDateString();

                return $night >= $checkIn && $night <= $lastNight;
            });

            $result[$departure->id] = $rows
                ->groupBy('room_id')
                ->map(fn (Collection $group): RoomNightClaim => self::representative($group))
                ->values();
        }

        return $result;
    }

    /**
     * @return Collection<int, RoomNightClaim>
     */
    public static function forDeparture(Departure $departure): Collection
    {
        return self::byDeparture(collect([$departure]))[$departure->id] ?? new Collection;
    }

    /**
     * Departures of this property whose stay overlaps the range.
     *
     * @return list<int>
     */
    public static function departureIds(int $propertyId, StayDates $stay): array
    {
        return Departure::query()
            ->where('property_id', $propertyId)
            ->where('date', '<', $stay->checkOut()->toDateString())
            ->whereRaw(
                'DATE_ADD(`date`, INTERVAL (SELECT nights FROM itineraries WHERE itineraries.id = departures.itinerary_id) DAY) > ?',
                [$stay->checkIn()->toDateString()],
            )
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  Collection<int, RoomNightClaim>  $group
     */
    private static function representative(Collection $group): RoomNightClaim
    {
        $live = $group->first(fn (RoomNightClaim $claim): bool => $claim->released_at === null);

        if ($live instanceof RoomNightClaim) {
            return $live;
        }

        $fallback = $group->sortByDesc('id')->first();

        if (! $fallback instanceof RoomNightClaim) {
            throw new RuntimeException('Claim group was empty.');
        }

        return $fallback;
    }
}
