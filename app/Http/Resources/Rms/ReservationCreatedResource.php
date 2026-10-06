<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\Booking;
use App\Support\Bookings\ReservationCreated;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReservationCreated
 */
class ReservationCreatedResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     bookings: list<array{
     *         id: int,
     *         reference: string|null,
     *         request_reference: string|null,
     *         display_reference: string|null,
     *         type: string,
     *         status: string,
     *         segment: string,
     *         main_channel: string,
     *         channel_of_origin: string,
     *         adults: int,
     *         children: int,
     *         party_label: string,
     *         total: int,
     *         balance: int,
     *         deposit_pct: int,
     *         deposit_amount: int,
     *         balance_days: int,
     *         balance_due_date: string,
     *         price_lines: list<array{code: string, label: string, amount: int}>,
     *         promo_code: string|null,
     *         online_deposit: bool,
     *         sold_on: string,
     *         rates_version: array{id: int, version: int},
     *         internal_notes: string|null,
     *         can_act: bool,
     *         allowed_transitions: list<array{to: string, reason_required: bool}>,
     *         room: array{id: int, code: string, label: string}|null,
     *         room_label: string,
     *         contact: array{id: int, name: string, email: string|null, phone: string|null, country: string|null, preferred_channel: string},
     *         group: array{id: int, reference: string, name: string, coordinator: array{id: int, name: string}}|null,
     *         owner: array{id: int, name: string},
     *         request: array{preferred_channel: string, travel_advisor: bool, notes: string|null, hold: array{expires_at: string|null, expired: bool, rule: string}, sla: array{due_at: string, remaining_minutes: int, breached: bool}}|null
     *     }>,
     *     group: array{id: int, reference: string, name: string, coordinator: array{id: int, name: string}}|null,
     *     warnings: list<string>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var ReservationCreated $created */
        $created = $this->resource;

        $created->bookings->load([
            'room',
            'contact',
            'group.coordinator',
            'owner',
            'ratesVersion',
            'bookingRequest',
            'activeClaims',
        ]);

        $group = $created->group;

        if ($group !== null) {
            $group->loadMissing('coordinator');
        }

        return [
            'bookings' => $created->bookings
                ->map(fn (Booking $booking): array => (new BookingResource($booking))->toArray($request))
                ->values()
                ->all(),
            'group' => $group === null ? null : [
                'id' => $group->id,
                'reference' => $group->reference,
                'name' => $group->name,
                'coordinator' => [
                    'id' => $group->coordinator->id,
                    'name' => $group->coordinator->name,
                ],
            ],
            'warnings' => $created->warnings,
        ];
    }
}
