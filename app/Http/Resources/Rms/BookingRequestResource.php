<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\Booking;
use App\Models\RoomType;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Support\Bookings\RequestParty;
use App\Support\Bookings\RequestSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class BookingRequestResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     display_reference: string|null,
     *     contact: array{name: string, preferred_channel: string},
     *     travel_advisor: bool,
     *     party: string,
     *     stay: array{check_in: string, check_out: string},
     *     nights: int,
     *     room_type: array{id: int, code: string, name: string}|null,
     *     rooms_count: int,
     *     copy: string,
     *     estimated_value: int,
     *     hold: array{expires_at: string|null, rule: string, remaining_business_minutes: int, expired: bool},
     *     sla: array{due_at: string, remaining_minutes: int, breached: bool},
     *     can_act: bool,
     *     source: string,
     *     agency_name: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'roomType',
            'contact',
            'bookingRequest',
            'activeClaims',
            'agency',
        ]);

        $actor = $request->user();
        $canAct = $actor instanceof User
            && app(BookingPolicy::class)->ownsOrMayActOnAny($actor, $this->resource);

        $summary = RequestSummary::for($this->resource);
        $hold = $summary['hold'] ?? [
            'expires_at' => null,
            'expired' => $this->holdExpired(),
            'rule' => '',
            'remaining_business_minutes' => 0,
        ];
        $sla = $summary['sla'] ?? [
            'due_at' => '',
            'remaining_minutes' => 0,
            'breached' => false,
        ];
        $stay = $this->stay();
        $type = $this->roomType;
        $rooms = RequestSummary::roomsCount($this->resource);

        return [
            'id' => $this->id,
            'display_reference' => $this->displayReference(),
            'contact' => [
                'name' => $this->contact->name,
                'preferred_channel' => $summary['preferred_channel'] ?? $this->contact->preferred_channel->value,
            ],
            'travel_advisor' => (bool) ($summary['travel_advisor'] ?? false),
            'party' => RequestParty::label($this->adults, $this->children),
            'stay' => [
                'check_in' => $stay->checkIn()->toDateString(),
                'check_out' => $stay->checkOut()->toDateString(),
            ],
            'nights' => $stay->nights(),
            'room_type' => $type instanceof RoomType ? [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
            ] : null,
            'rooms_count' => $rooms,
            'copy' => $summary['copy'] ?? RequestSummary::line($rooms, $stay),
            'estimated_value' => $this->total,
            'hold' => $hold,
            'sla' => $sla,
            'can_act' => $canAct,
            'source' => $this->requestSource(),
            'agency_name' => $this->agency?->name,
        ];
    }

    private function requestSource(): string
    {
        if ($this->checkout_session_id !== null) {
            return 'engine';
        }

        if ($this->agency_id !== null) {
            return 'portal';
        }

        return 'rms';
    }
}
