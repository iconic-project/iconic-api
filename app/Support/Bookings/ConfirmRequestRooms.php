<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Enums\ClaimKind;
use App\Exceptions\RoomUnavailableException;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Inventory\RoomAllocator;
use App\Support\Inventory\StaffStayRestrictions;
use App\Support\Stays\StayDates;
use Illuminate\Validation\ValidationException;

/**
 * A request whose hold expired is offered its original room when that room
 * is still free, or one other room of the type. Confirm must name the offer.
 */
final class ConfirmRequestRooms
{
    public function __construct(
        private readonly RoomAllocator $allocator,
        private readonly StaffStayRestrictions $restrictions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{alternative: bool, convert: bool, room: Room}
     */
    public function preview(Booking $booking, ?User $actor, array $data): array
    {
        return $this->resolve($booking, $actor, $data, false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{alternative: bool, convert: bool, room: Room}
     */
    public function accepted(Booking $booking, ?User $actor, array $data): array
    {
        return $this->resolve($booking, $actor, $data, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{alternative: bool, convert: bool, room: Room}
     */
    private function resolve(Booking $booking, ?User $actor, array $data, bool $acceptRequired): array
    {
        $booking->loadMissing(['room.roomType', 'roomType']);
        $current = $booking->room;
        $type = $booking->roomType instanceof RoomType ? $booking->roomType : $current?->roomType;
        $stay = $booking->stay();

        if (! $current instanceof Room || ! $type instanceof RoomType) {
            throw new RoomUnavailableException(
                $type instanceof RoomType ? $type->name : 'Room',
                $stay->checkIn()->toDateString(),
            );
        }

        if ($this->liveHold($booking)) {
            return [
                'alternative' => false,
                'convert' => true,
                'room' => $current,
            ];
        }

        if ($this->free($current, $stay)) {
            $this->guardSell($current, $stay, $actor, $data);

            return [
                'alternative' => false,
                'convert' => false,
                'room' => $current,
            ];
        }

        $picked = $this->alternative($type, $stay, (int) $current->id);

        if (! $picked instanceof Room) {
            throw new RoomUnavailableException($type->name, $stay->checkIn()->toDateString());
        }

        $accepted = isset($data['room_id']) && is_numeric($data['room_id']) ? (int) $data['room_id'] : 0;

        if ($acceptRequired && $accepted !== $picked->id) {
            throw ValidationException::withMessages([
                'room_id' => ['The original room was taken. Confirm '.$picked->label.' to take that room.'],
            ]);
        }

        $this->guardSell($picked, $stay, $actor, $data);

        return [
            'alternative' => true,
            'convert' => false,
            'room' => $picked,
        ];
    }

    private function alternative(RoomType $type, StayDates $stay, int $exceptRoomId): ?Room
    {
        try {
            $picked = $this->allocator->pick($type, $stay, 1, [$exceptRoomId])->first();
        } catch (RoomUnavailableException) {
            return null;
        }

        return $picked instanceof Room ? $picked : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guardSell(Room $room, StayDates $stay, ?User $actor, array $data): void
    {
        if (! $actor instanceof User) {
            return;
        }

        $this->restrictions->check([$room], $stay, $actor, $data);
    }

    private function liveHold(Booking $booking): bool
    {
        return RoomNightClaim::query()
            ->where('holder_type', $booking->getMorphClass())
            ->where('holder_id', $booking->getKey())
            ->where('kind', ClaimKind::Hold)
            ->whereNull('released_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->exists();
    }

    private function free(Room $room, StayDates $stay): bool
    {
        return ! RoomNightClaim::query()
            ->where('room_id', $room->id)
            ->whereDate('night', '>=', $stay->checkIn()->toDateString())
            ->whereDate('night', '<=', $stay->lastNight()->toDateString())
            ->whereNull('released_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->exists();
    }

    public static function taken(): string
    {
        return "The room was taken after this request's hold expired.";
    }
}
