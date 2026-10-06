<?php

declare(strict_types=1);

namespace App\Services\Engine;

use App\Models\Property;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\NightAvailability;
use App\Services\Inventory\Restrictions;
use App\Support\Content\Completeness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Price calendar. Inventory comes from NightAvailability in chunks of 62 nights.
 * A night is available when any engine-visible type can be booked for one night
 * by the party. from_price is the lowest season price among those types.
 */
final class EngineCalendar
{
    public function __construct(
        private readonly NightAvailability $availability,
        private readonly Restrictions $restrictions,
        private readonly SeasonNightly $prices,
        private readonly CurrentConfig $config,
        private readonly EnginePropertyFeed $properties,
    ) {}

    /**
     * @return array{from: string, months: int, adults: int, children: int, nights: list<array{night: string, available: bool, from_price: int|null, closed_to_arrival: bool, closed_to_departure: bool, min_stay: int}>}
     */
    public function month(string $from, int $months, int $adults, int $children): array
    {
        $version = EngineFeedVersion::current();

        /** @var array{from: string, months: int, adults: int, children: int, nights: list<array{night: string, available: bool, from_price: int|null, closed_to_arrival: bool, closed_to_departure: bool, min_stay: int}>} $payload */
        $payload = Cache::remember(
            EngineFeedVersion::calendarKey($from, $months, $adults, $children, $version),
            60,
            fn (): array => $this->assemble($from, $months, $adults, $children),
        );

        return $payload;
    }

    /**
     * @return array{from: string, months: int, adults: int, children: int, nights: list<array{night: string, available: bool, from_price: int|null, closed_to_arrival: bool, closed_to_departure: bool, min_stay: int}>}
     */
    private function assemble(string $from, int $months, int $adults, int $children): array
    {
        $property = $this->publishedProperty();
        $types = $this->types($property, $adults, $children);
        $start = CarbonImmutable::parse($from.'-01');
        $end = $start->addMonths($months);
        $flags = $this->restrictions->forTypes(
            $property->id,
            $types,
            $start->toDateString(),
            $end->toDateString(),
        );
        $counts = $this->counts($property, $start, $end);
        $ages = $this->ages($children);
        $nights = [];
        $cursor = $start;

        while ($cursor->lessThan($end)) {
            $night = $cursor->toDateString();
            $next = $cursor->addDay()->toDateString();
            $available = false;
            $fromPrice = null;
            $closedToArrival = $types !== [];
            $closedToDeparture = $types !== [];
            $minStay = null;

            foreach ($types as $type) {
                $flag = $flags[$night][$type->id];
                $depart = $flags[$next][$type->id];
                $closedToArrival = $closedToArrival && $flag['closed_to_arrival'];
                $closedToDeparture = $closedToDeparture && $flag['closed_to_departure'];
                $minStay = $minStay === null ? $flag['min_stay'] : min($minStay, $flag['min_stay']);

                if ($flag['stop_sell'] || $flag['closed_to_arrival'] || $depart['closed_to_departure']) {
                    continue;
                }

                if ($flag['min_stay'] > 1 || $flag['max_stay'] < 1) {
                    continue;
                }

                $free = $counts[$night][$type->code]['free'] ?? 0;

                if ($free < 1) {
                    continue;
                }

                $price = $this->prices->price($type, $night, $adults, $ages);

                if ($price === null) {
                    continue;
                }

                $available = true;
                $fromPrice = $fromPrice === null ? $price : min($fromPrice, $price);
            }

            $nights[] = [
                'night' => $night,
                'available' => $available,
                'from_price' => $fromPrice,
                'closed_to_arrival' => $closedToArrival,
                'closed_to_departure' => $closedToDeparture,
                'min_stay' => $minStay ?? $this->config->businessRules()->stay->minNights,
            ];

            $cursor = $cursor->addDay();
        }

        return [
            'from' => $from,
            'months' => $months,
            'adults' => $adults,
            'children' => $children,
            'nights' => $nights,
        ];
    }

    private function publishedProperty(): Property
    {
        return $this->properties->property();
    }

    /**
     * @return list<RoomType>
     */
    private function types(Property $property, int $adults, int $children): array
    {
        $property->loadMissing('roomTypes');
        $types = [];

        foreach ($property->roomTypes as $type) {
            if (! Completeness::engineVisible($type)) {
                continue;
            }

            if ($adults > $type->max_adults || $children > $type->max_children || $adults + $children > $type->max_occupancy) {
                continue;
            }

            $types[] = $type;
        }

        return $types;
    }

    /**
     * @return array<string, array<string, array{total: int, free: int, held: int, sold: int, blocked: int}>>
     */
    private function counts(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = [];
        $cursor = $from;

        while ($cursor->lessThan($to)) {
            $end = $cursor->addDays(NightAvailability::MAX_NIGHTS);

            if ($end->greaterThan($to)) {
                $end = $to;
            }

            if (! $end->greaterThan($cursor)) {
                break;
            }

            $counts += $this->availability->countsByType($property, $cursor, $end);
            $cursor = $end;
        }

        return $counts;
    }

    /**
     * @return list<int>
     */
    private function ages(int $children): array
    {
        if ($children < 1) {
            return [];
        }

        return array_fill(0, $children, $this->config->engineSettings()->guests->childMaxAge);
    }
}
