<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\ReleaseReason;
use App\Enums\RoomStatus;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Support\Bookings\FrontDeskLock;
use App\Support\BusinessTime;
use App\Support\History\History;
use App\Support\Stays\StayClock;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CheckInBooking extends Action
{
    public function __construct(
        private StayClock $clock,
        private CurrentConfig $config,
        private ClaimService $claims,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $booking = FrontDeskLock::acquire($booking);
            $stay = $booking->stay();

            if (! $this->clock->isArrivalDayOrLater($stay)) {
                throw ValidationException::withMessages([
                    'check_in' => ['Check-in opens on the arrival day.'],
                ]);
            }

            $this->assertPayable($booking);
            $at = $this->moment($data, $stay);
            $previousRoomId = $booking->room_id;
            $roomLabel = $this->moveRoom($booking, $stay, $data);
            $from = $booking->status;

            $booking->status = BookingStatus::InHouse;
            $booking->checked_in_at = Carbon::instance($at);
            $booking->save();

            $what = 'Checked in';

            if ($roomLabel !== null) {
                $what .= ' · room '.$roomLabel;
            }

            History::record($booking, 'booking.checked_in', before: [
                'status' => $from->value,
                'room_id' => $previousRoomId,
            ], after: [
                'status' => $booking->status->value,
                'checked_in_at' => $at->utc()->toIso8601String(),
                'room_id' => $booking->room_id,
                'what' => $what,
            ], actor: $actor);

            BookingStatusChanged::dispatch($booking, $from, $booking->status);

            return $booking->refresh()->load(['room', 'roomType', 'property', 'contact', 'owner']);
        });
    }

    private function assertPayable(Booking $booking): void
    {
        if ($booking->status === BookingStatus::FullyPaid) {
            return;
        }

        if ($booking->status === BookingStatus::Confirmed
            && ! $this->config->businessRules()->stay->checkInRequiresFullPayment
        ) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => ['Check-in needs a fully paid booking, or a confirmed booking when full payment is not required.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function moment(array $data, StayDates $stay): CarbonImmutable
    {
        $at = isset($data['at']) && is_string($data['at']) && $data['at'] !== ''
            ? CarbonImmutable::parse($data['at'])
            : CarbonImmutable::now();
        $start = BusinessTime::calendarDay($stay->checkIn()->toDateString())->utc();
        $now = CarbonImmutable::now()->utc();

        if ($at->utc()->lt($start) || $at->utc()->gt($now)) {
            throw ValidationException::withMessages([
                'at' => ['Check-in time must be on or after arrival-day midnight and not in the future.'],
            ]);
        }

        return $at->utc();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function moveRoom(Booking $booking, StayDates $stay, array $data): ?string
    {
        $roomId = $data['room_id'] ?? null;

        if ($roomId === null || $roomId === '' || (int) $roomId === (int) $booking->room_id) {
            return null;
        }

        $room = Room::query()->find((int) $roomId);

        if (! $room instanceof Room
            || $room->status !== RoomStatus::Active
            || $room->property_id !== (int) $booking->property_id
        ) {
            throw ValidationException::withMessages([
                'room_id' => ['Pick an active room at this property.'],
            ]);
        }

        $this->claims->release($booking, ReleaseReason::Moved);

        try {
            $this->claims->claim($stay, new Collection([$room]), $booking, ClaimKind::Booking);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'room_id' => [$exception->getMessage()],
            ]);
        }

        $booking->room_id = $room->id;
        $booking->room_type_id = $room->room_type_id;

        return $room->label;
    }
}
