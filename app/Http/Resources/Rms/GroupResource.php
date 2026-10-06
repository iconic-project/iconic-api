<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\Booking;
use App\Models\Group;
use App\Models\Property;
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
     *     property: array{id: int, code: string, name: string}|null,
     *     rooms: list<string>,
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
            'bookings.room',
            'bookings.property',
        ]);

        $bookings = $this->bookings;
        $property = $bookings->first()?->property;

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
            'property' => $property instanceof Property ? [
                'id' => $property->id,
                'code' => $property->code,
                'name' => $property->name,
            ] : null,
            'rooms' => $bookings
                ->map(fn (Booking $booking): string => $booking->roomLabel())
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
