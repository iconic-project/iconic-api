<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalStayRatesResource extends JsonResource
{
    public static $wrap = null;

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
    public function toArray(Request $request): array
    {
        /** @var array{
         *     commission_pct: int,
         *     currency: string,
         *     stay: array{min_nights: int, max_nights: int, max_rooms: int},
         *     seasons: list<array{code: string, name: string, from: string, to: string}>,
         *     room_types: list<array{code: string, name: string}>,
         *     room_rates: list<array{room_type: string, season: string, nightly: int}>,
         *     rate_plans: list<array<string, mixed>>,
         *     length_of_stay: list<array{min_nights: int, discount_pct: int}>,
         *     supplements: list<array{code: string, label: string, from: string, to: string, per_night: int, basis: string}>
         * } $payload
         */
        $payload = $this->resource;

        return $payload;
    }
}
