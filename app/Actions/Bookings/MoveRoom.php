<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Enums\ClaimKind;
use App\Enums\ReleaseReason;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\User;
use App\Services\Inventory\ClaimService;
use App\Support\Bookings\FrontDeskLock;
use App\Support\BusinessTime;
use App\Support\History\History;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Same dates, different room. An upgrade is free unless reprice is set,
 * in which case the stay is priced again through ModifyStay.
 */
final class MoveRoom extends Action
{
    public function __construct(private ClaimService $claims) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function preview(Booking $booking, array $data, User $actor): array
    {
        if ((bool) ($data['reprice'] ?? false)) {
            return app(ModifyStay::class)->preview($booking, $this->modifyData($booking, $data), $actor);
        }

        $room = $this->room($booking, $data);
        $stay = $booking->stay();
        $nights = [];

        foreach ($stay->eachNight() as $night) {
            $nights[] = $night->toDateString();
        }

        $taken = RoomNightClaim::query()
            ->where('room_id', $room->id)
            ->whereIn('night', $nights)
            ->whereNull('released_at')
            ->exists();

        return [
            'available' => ! $taken,
            'check_in' => $stay->checkIn()->toDateString(),
            'check_out' => $stay->checkOut()->toDateString(),
            'nights' => $stay->nights(),
            'night_lines' => $booking->night_lines ?? [],
            'price_lines' => $booking->price_lines,
            'tax_lines' => $booking->tax_lines ?? [],
            'credit' => 0,
            'penalty' => 0,
            'penalty_pct' => 0,
            'penalty_waived' => false,
            'modification_fee' => 0,
            'length_of_stay' => 0,
            'current_total' => $booking->total,
            'new_total' => $booking->total,
            'balance' => $booking->balance(),
            'refund_due' => 0,
            'status' => $booking->status->value,
            'room_id' => $room->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        if ((bool) ($data['reprice'] ?? false)) {
            return app(ModifyStay::class)->handle($booking, $this->modifyData($booking, $data), $actor);
        }

        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $booking = FrontDeskLock::acquire($booking);

            if (! ModifyStay::allows($booking->status)) {
                throw ValidationException::withMessages([
                    'status' => ['A stay can be changed while it is requested, pending, confirmed, fully paid, or in house.'],
                ]);
            }

            $stay = $booking->stay();

            if ($stay->checkIn()->toDateString() < BusinessTime::now()->toDateString()) {
                throw ValidationException::withMessages([
                    'room_id' => ['A stay that has already started cannot move to another room.'],
                ]);
            }

            $room = $this->room($booking, $data);
            $before = [
                'room_id' => $booking->room_id,
                'check_in' => $stay->checkIn()->toDateString(),
                'check_out' => $stay->checkOut()->toDateString(),
                'nights' => $stay->nights(),
                'total' => $booking->total,
            ];

            $this->claims->claim($stay, new Collection([$room]), $booking, ClaimKind::Booking);

            $this->claims->release(
                $booking,
                ReleaseReason::Moved,
                rooms: Room::query()->whereKey($before['room_id'])->get(),
            );

            $booking->room_id = $room->id;
            $booking->room_type_id = $room->room_type_id;
            $booking->save();

            History::record($booking, 'booking.moved', before: $before, after: [
                'room_id' => $room->id,
                'check_in' => $before['check_in'],
                'check_out' => $before['check_out'],
                'nights' => $before['nights'],
                'total' => $booking->total,
                'what' => 'Room moved · '.$room->label,
            ], reason: $this->reason($data), actor: $actor);

            return $booking->refresh()->load(['room', 'roomType', 'property', 'contact', 'owner']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function modifyData(Booking $booking, array $data): array
    {
        $stay = $booking->stay();

        return [
            ...$data,
            'check_in' => $stay->checkIn()->toDateString(),
            'check_out' => $stay->checkOut()->toDateString(),
            'reprice' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function room(Booking $booking, array $data): Room
    {
        $room = Room::query()->with('roomType')->find((int) ($data['room_id'] ?? 0));

        if (! $room instanceof Room || $room->status !== RoomStatus::Active || (int) $room->property_id !== (int) $booking->property_id) {
            throw ValidationException::withMessages([
                'room_id' => ['Pick an active room at this property.'],
            ]);
        }

        if ((int) $room->id === (int) $booking->room_id) {
            throw ValidationException::withMessages([
                'room_id' => ['Pick a different room.'],
            ]);
        }

        $children = count($booking->child_ages ?? []);

        if ($booking->adults > $room->roomType->max_adults || $booking->adults + $children > $room->roomType->max_occupancy) {
            throw ValidationException::withMessages([
                'room_id' => ['That room cannot hold this party.'],
            ]);
        }

        return $room;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reason(array $data): string
    {
        $reason = isset($data['reason']) && is_string($data['reason']) ? trim($data['reason']) : '';

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A reason is required to move the room.'],
            ]);
        }

        return $reason;
    }
}
