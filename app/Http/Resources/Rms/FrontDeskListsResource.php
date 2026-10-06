<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Support\FrontDesk\FrontDeskDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FrontDeskListsResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{date: string, arrivals: mixed, in_house: mixed, departures: mixed}
     */
    public function toArray(Request $request): array
    {
        $day = $this->resource;

        if (! $day instanceof FrontDeskDay) {
            return [
                'date' => '',
                'arrivals' => [],
                'in_house' => [],
                'departures' => [],
            ];
        }

        return [
            'date' => $day->date,
            'arrivals' => BookingResource::collection($day->arrivals)->resolve(),
            'in_house' => BookingResource::collection($day->inHouse)->resolve(),
            'departures' => BookingResource::collection($day->departures)->resolve(),
        ];
    }
}
