<?php

declare(strict_types=1);

namespace App\Support\Portal;

use App\Models\Agency;
use App\Models\RoomType;
use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\RatesDocument;

/**
 * Published seasons, plans, length-of-stay and supplements, with money
 * fields net of the agency commission. Public amounts are not copied.
 */
final class PortalStayRates
{
    /**
     * @return array{
     *     commission_pct: int,
     *     currency: string,
     *     stay: array{min_nights: int, max_nights: int, max_rooms: int},
     *     seasons: list<array{code: string, name: string, from: string, to: string}>,
     *     room_types: list<array{code: string, name: string}>,
     *     room_rates: list<array{room_type: string, season: string, nightly: int}>,
     *     rate_plans: list<array<string, mixed>>,
     *     length_of_stay: list<array{min_nights: int, discount_pct: int}>,
     *     supplements: list<array{code: string, label: string, from: string, to: string, per_night: int, basis: string}>
     * }
     */
    public static function document(Agency $agency, RatesDocument $rates, CurrentConfig $config): array
    {
        $stay = $config->businessRules()->stay;
        /** @var array<string, string> $byCode */
        $byCode = [];

        foreach ($rates->roomRates as $rate) {
            $byCode[$rate->roomType] = $rate->roomType;
        }

        if ($byCode !== []) {
            $named = RoomType::query()
                ->whereIn('code', array_keys($byCode))
                ->orderBy('sort')
                ->orderBy('id')
                ->get(['code', 'name']);

            foreach ($named as $type) {
                $byCode[$type->code] = $type->name;
            }
        }

        $roomTypes = [];

        foreach ($byCode as $code => $name) {
            $roomTypes[] = [
                'code' => $code,
                'name' => $name,
            ];
        }

        $roomRates = [];

        foreach ($rates->roomRates as $rate) {
            $roomRates[] = [
                'room_type' => $rate->roomType,
                'season' => $rate->season,
                'nightly' => $agency->netOf($rate->nightly),
            ];
        }

        $plans = [];

        foreach ($rates->ratePlans as $plan) {
            $plans[] = $plan->toArray();
        }

        $bands = [];

        foreach ($rates->lengthOfStay as $band) {
            $bands[] = $band->toArray();
        }

        $supplements = [];

        foreach ($rates->supplements as $supplement) {
            $row = $supplement->toArray();
            $row['per_night'] = $agency->netOf($supplement->perNight);
            $supplements[] = $row;
        }

        $seasons = [];

        foreach ($rates->seasons as $season) {
            $seasons[] = $season->toArray();
        }

        return [
            'commission_pct' => $agency->commission_pct,
            'currency' => $rates->currency,
            'stay' => [
                'min_nights' => $stay->minNights,
                'max_nights' => $stay->maxNights,
                'max_rooms' => $stay->maxRoomsPerBooking,
            ],
            'seasons' => $seasons,
            'room_types' => $roomTypes,
            'room_rates' => $roomRates,
            'rate_plans' => $plans,
            'length_of_stay' => $bands,
            'supplements' => $supplements,
        ];
    }
}
