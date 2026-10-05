<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Enums\BookingStatus;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\User;
use App\Support\Bookings\FrontDeskLock;
use App\Support\BusinessTime;
use App\Support\History\History;
use App\Support\Stays\StayClock;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class CheckOutBooking extends Action
{
    public function __construct(
        private StayClock $clock,
        private ModifyStay $modify,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $booking = FrontDeskLock::acquire($booking);

            if ($booking->status !== BookingStatus::InHouse) {
                throw ValidationException::withMessages([
                    'status' => ['Check-out is only from in house.'],
                ]);
            }

            $stay = $booking->stay();
            $at = $this->moment($data, $stay);
            $today = $this->clock->today()->toDateString();
            $checkIn = $stay->checkIn()->toDateString();
            $checkOut = $stay->checkOut()->toDateString();

            if ($today < $checkOut) {
                if ($today <= $checkIn) {
                    throw ValidationException::withMessages([
                        'check_out' => ['Early departure on the arrival day would leave no night.'],
                    ]);
                }

                $reason = $this->reason($data);

                if ($reason === null) {
                    throw ValidationException::withMessages([
                        'reason' => ['A reason is required for an early departure.'],
                    ]);
                }

                $booking = $this->modify->shorten(
                    $booking,
                    StayDates::of($checkIn, $today),
                    $reason,
                    $actor,
                );
                $stay = $booking->stay();
            }

            $late = $at->greaterThan($this->clock->checkOutMoment($stay));
            $from = $booking->status;
            $booking->status = BookingStatus::CheckedOut;
            $booking->checked_out_at = Carbon::instance($at);
            $booking->save();

            $what = $late
                ? 'Checked out · late check-out recorded, not charged'
                : 'Checked out';

            History::record($booking, 'booking.checked_out', before: [
                'status' => $from->value,
            ], after: [
                'status' => $booking->status->value,
                'checked_out_at' => $at->utc()->toIso8601String(),
                'late' => $late,
                'what' => $what,
            ], reason: $this->reason($data), actor: $actor);

            BookingStatusChanged::dispatch($booking, $from, $booking->status);

            return $booking->refresh()->load(['room', 'roomType', 'property', 'contact', 'owner']);
        });
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
                'at' => ['Check-out time must be on or after arrival-day midnight and not in the future.'],
            ]);
        }

        return $at->utc();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reason(array $data): ?string
    {
        if (! isset($data['reason']) || ! is_string($data['reason'])) {
            return null;
        }

        $reason = trim($data['reason']);

        return $reason === '' ? null : $reason;
    }
}
