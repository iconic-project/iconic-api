<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ConfigKind;
use App\Models\RoomType;
use App\Models\StayRestriction;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Config\Documents\StayRules;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sell rules for one room type and one stay.
 *
 * A type row beats a property-wide row for that night. The winning row is used
 * whole: a null min or max on it falls through to the business-rule default,
 * not to the row it beat. Bools on the winning row are the value.
 */
final class Restrictions
{
    public function __construct(
        private readonly CurrentConfig $config,
    ) {}

    public function evaluate(RoomType $type, StayDates $stay): RestrictionResult
    {
        $rows = $this->rows($type, $stay);
        $reasons = [];

        foreach ($stay->eachNight() as $night) {
            $row = $this->winning($rows, $night->toDateString());

            if ($row?->stop_sell) {
                $reasons[] = 'STOP_SELL';

                break;
            }
        }

        $arrival = $this->winning($rows, $stay->checkIn()->toDateString());

        if ($arrival?->closed_to_arrival) {
            $reasons[] = 'CLOSED_TO_ARRIVAL';
        }

        $departure = $this->winning($rows, $stay->checkOut()->toDateString());

        if ($departure?->closed_to_departure) {
            $reasons[] = 'CLOSED_TO_DEPARTURE';
        }

        $defaults = $this->defaults();
        $min = $defaults->minNights;
        $max = $defaults->maxNights;

        if ($arrival instanceof StayRestriction) {
            if ($arrival->min_stay !== null) {
                $min = $arrival->min_stay;
            }

            if ($arrival->max_stay !== null) {
                $max = $arrival->max_stay;
            }
        }
        $nights = $stay->nights();

        if ($nights < $min) {
            $reasons[] = 'MIN_STAY:'.$min;
        }

        if ($nights > $max) {
            $reasons[] = 'MAX_STAY:'.$max;
        }

        return new RestrictionResult(Bookability::ordered($reasons));
    }

    /**
     * One query for the inclusive range. A type row beats a property-wide row.
     * A null min or max on the winning row falls through to the business-rule default.
     *
     * @param  list<RoomType>  $types
     * @return array<string, array<int, array{stop_sell: bool, closed_to_arrival: bool, closed_to_departure: bool, min_stay: int, max_stay: int}>>
     */
    public function forTypes(int $propertyId, array $types, string $from, string $to): array
    {
        $ids = [];

        foreach ($types as $type) {
            $ids[] = $type->id;
        }

        $loaded = StayRestriction::query()
            ->where('property_id', $propertyId)
            ->whereBetween('night', [$from, $to])
            ->where(function (Builder $query) use ($ids): void {
                $query->whereNull('room_type_id');

                if ($ids !== []) {
                    $query->orWhereIn('room_type_id', $ids);
                }
            })
            ->get();

        /** @var array<string, array{type: array<int, StayRestriction>, property?: StayRestriction}> $byNight */
        $byNight = [];

        foreach ($loaded as $row) {
            $night = $row->night->toDateString();

            if ($row->room_type_id === null) {
                $byNight[$night]['property'] = $row;
            } else {
                $byNight[$night]['type'][$row->room_type_id] = $row;
            }
        }

        $defaults = $this->defaults();
        $flags = [];
        $cursor = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        while ($cursor->lessThanOrEqualTo($end)) {
            $night = $cursor->toDateString();

            foreach ($types as $type) {
                $row = $byNight[$night]['type'][$type->id] ?? $byNight[$night]['property'] ?? null;
                $min = $defaults->minNights;
                $max = $defaults->maxNights;

                if ($row instanceof StayRestriction) {
                    if ($row->min_stay !== null) {
                        $min = $row->min_stay;
                    }

                    if ($row->max_stay !== null) {
                        $max = $row->max_stay;
                    }
                }

                $flags[$night][$type->id] = [
                    'stop_sell' => $row instanceof StayRestriction && $row->stop_sell,
                    'closed_to_arrival' => $row instanceof StayRestriction && $row->closed_to_arrival,
                    'closed_to_departure' => $row instanceof StayRestriction && $row->closed_to_departure,
                    'min_stay' => $min,
                    'max_stay' => $max,
                ];
            }

            $cursor = $cursor->addDay();
        }

        return $flags;
    }

    /**
     * Stay nights plus the check-out date. Closed-to-departure lives on the
     * check-out date, which is not a night of the stay.
     *
     * @return array<string, array{type?: StayRestriction, property?: StayRestriction}>
     */
    private function rows(RoomType $type, StayDates $stay): array
    {
        $loaded = StayRestriction::query()
            ->where('property_id', $type->property_id)
            ->whereBetween('night', [
                $stay->checkIn()->toDateString(),
                $stay->checkOut()->toDateString(),
            ])
            ->where(function (Builder $query) use ($type): void {
                $query->whereNull('room_type_id')
                    ->orWhere('room_type_id', $type->id);
            })
            ->get();

        $byNight = [];

        foreach ($loaded as $row) {
            $night = $row->night->toDateString();

            if ($row->room_type_id === null) {
                $byNight[$night]['property'] = $row;
            } else {
                $byNight[$night]['type'] = $row;
            }
        }

        return $byNight;
    }

    /**
     * @param  array<string, array{type?: StayRestriction, property?: StayRestriction}>  $rows
     */
    private function winning(array $rows, string $night): ?StayRestriction
    {
        return $rows[$night]['type'] ?? $rows[$night]['property'] ?? null;
    }

    private function defaults(): StayRules
    {
        if (! $this->config->has(ConfigKind::BusinessRules)) {
            return BusinessRulesDocument::fromArray(BusinessRulesDocument::initial())->stay;
        }

        return $this->config->businessRules()->stay;
    }
}
