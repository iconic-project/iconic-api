<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalCalendarResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     from: string,
     *     months: int,
     *     adults: int,
     *     children: int,
     *     nights: list<array{night: string, available: bool, from_price: int|null, closed_to_arrival: bool, closed_to_departure: bool, min_stay: int}>,
     *     commission_pct: int,
     *     stay: array{min_nights: int, max_nights: int, max_rooms: int}
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{
         *     from: string,
         *     months: int,
         *     adults: int,
         *     children: int,
         *     nights: list<array{night: string, available: bool, from_price: int|null, closed_to_arrival: bool, closed_to_departure: bool, min_stay: int}>,
         *     commission_pct: int,
         *     stay: array{min_nights: int, max_nights: int, max_rooms: int}
         * } $payload
         */
        $payload = $this->resource;

        return $payload;
    }
}
