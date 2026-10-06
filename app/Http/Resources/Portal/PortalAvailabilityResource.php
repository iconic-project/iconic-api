<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalAvailabilityResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     check_in: string,
     *     check_out: string,
     *     adults: int,
     *     child_ages: list<int>,
     *     rooms: int,
     *     commission_pct: int,
     *     stay: array{min_nights: int, max_nights: int, max_rooms: int},
     *     room_types: list<array<string, mixed>>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{
         *     check_in: string,
         *     check_out: string,
         *     adults: int,
         *     child_ages: list<int>,
         *     rooms: int,
         *     commission_pct: int,
         *     stay: array{min_nights: int, max_nights: int, max_rooms: int},
         *     room_types: list<array<string, mixed>>
         * } $payload
         */
        $payload = $this->resource;

        return $payload;
    }
}
