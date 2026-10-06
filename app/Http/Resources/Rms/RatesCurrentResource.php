<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use Illuminate\Http\Request;

class RatesCurrentResource extends ConfigCurrentResource
{
    /**
     * @return array{
     *     version: int,
     *     document: array{
     *         currency: string,
     *         schema_version: int,
     *         seasons: list<array{code: string, name: string, from: string, to: string}>,
     *         room_rates: list<array{room_type: string, season: string, nightly: int}>,
     *         occupancy: array{extra_adult_nightly: int, extra_child_nightly: int, single_occupancy_pct: int},
     *         day_of_week: array<string, int>,
     *         length_of_stay: list<array{min_nights: int, discount_pct: int}>,
     *         supplements: list<array{code: string, label: string, from: string, to: string, per_night: int, basis: string}>,
     *         rate_plans: list<array{code: string, name: string, default: bool, adjust_pct: int, refundable: bool, deposit_pct: int, balance_days: int, cancellation: string, meal_plan: string}>
     *     },
     *     published_at: string,
     *     published_by: array{id: int, name: string}|null,
     *     approval_reference: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
