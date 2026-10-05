<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\Booking;
use App\Models\Departure;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Group
 */
class GroupResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     reference: string,
     *     name: string,
     *     coordinator: array{id: int, name: string, email: string|null, preferred_channel: string},
     *     departure: array{id: int, date: string, property: array{id: int, code: string, name: string}}|null,
     *     cabins: list<string>,
     *     guests: int,
     *     total: int,
     *     balance: int,
     *     statuses: list<string>
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'coordinator',
            'departure.property',
            'bookings.cabin',
        ]);

        $bookings = $this->bookings;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->name,
            'coordinator' => [
                'id' => $this->coordinator->id,
                'name' => $this->coordinator->name,
                'email' => $this->coordinator->email,
                'preferred_channel' => $this->coordinator->preferred_channel->value,
            ],
            'departure' => $this->departure instanceof Departure ? [
                'id' => $this->departure->id,
                'date' => $this->departure->date->toDateString(),
                'property' => [
                    'id' => $this->departure->property->id,
                    'code' => $this->departure->property->code,
                    'name' => $this->departure->property->name,
                ],
            ] : null,
            'cabins' => $bookings
                ->map(fn (Booking $booking): string => $booking->cabinLabel())
                ->values()
                ->all(),
            'guests' => (int) $bookings->sum(fn (Booking $booking): int => $booking->adults + $booking->children),
            'total' => (int) $bookings->sum('total'),
            'balance' => (int) $bookings->sum(fn (Booking $booking): int => $booking->balance()),
            'statuses' => $bookings
                ->map(fn (Booking $booking): string => $booking->status->value)
                ->unique()
                ->values()
                ->all(),
        ];
    }
}
