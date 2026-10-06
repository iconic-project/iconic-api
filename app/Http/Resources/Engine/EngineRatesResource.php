<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Support\Config\Documents\RatesDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RatesDocument
 */
class EngineRatesResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     currency: string,
     *     schema_version: int,
     *     seasons: list<array{code: string, name: string, from: string, to: string}>,
     *     room_rates: list<array{room_type: string, season: string, nightly: int}>,
     *     occupancy: array{extra_adult_nightly: int, extra_child_nightly: int, single_occupancy_pct: int},
     *     day_of_week: array<string, int>,
     *     length_of_stay: list<array{min_nights: int, discount_pct: int}>,
     *     supplements: list<array{code: string, label: string, from: string, to: string, per_night: int, basis: string}>,
     *     rate_plans: list<array{code: string, name: string, default: bool, adjust_pct: int, refundable: bool, deposit_pct: int, balance_days: int, cancellation: string, meal_plan: string}>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var RatesDocument $rates */
        $rates = $this->resource;

        return $rates->toArray();
    }
}
