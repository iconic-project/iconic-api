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
     *     check_in: string,
     *     check_out: string,
     *     room_label: string,
     *     guests: list<CompleteGuestResource>
     * }
     *
     * @phpstan-return array{
     *     id: int,
     *     reference: string|null,
     *     property: string,
     *     check_in: string,
     *     check_out: string,
     *     room_label: string,
     *     guests: AnonymousResourceCollection
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['property', 'roomType', 'guests']);

        return [
            'id' => $this->id,
            'reference' => $this->displayReference(),
            'property' => $this->property->name,
            'check_in' => $this->check_in->toDateString(),
            'check_out' => $this->check_out->toDateString(),
            'room_label' => $this->roomType->name,
            'guests' => CompleteGuestResource::collection($this->guests),
        ];
    }
}
