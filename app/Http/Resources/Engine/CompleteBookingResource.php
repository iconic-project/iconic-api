<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class CompleteBookingResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     reference: string|null,
     *     property: string,
     *     departure_date: string,
     *     return_date: string,
     *     itinerary_name: string,
     *     cabin_label: string,
     *     guests: list<CompleteGuestResource>
     * }
     *
     * @phpstan-return array{
     *     id: int,
     *     reference: string|null,
     *     property: string,
     *     departure_date: string,
     *     return_date: string,
     *     itinerary_name: string,
     *     cabin_label: string,
     *     guests: AnonymousResourceCollection
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['departure.property', 'departure.itinerary', 'cabin', 'guests']);

        return [
            'id' => $this->id,
            'reference' => $this->displayReference(),
            'property' => $this->departure->property->name,
            'departure_date' => $this->departure->date->toDateString(),
            'return_date' => $this->departure->returnDate()->toDateString(),
            'itinerary_name' => $this->departure->itinerary->name,
            'cabin_label' => $this->cabinLabel(),
            'guests' => CompleteGuestResource::collection($this->guests),
        ];
    }
}
