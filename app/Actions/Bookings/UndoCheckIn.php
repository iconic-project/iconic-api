<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Enums\BookingStatus;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\User;
use App\Support\Bookings\FrontDeskLock;
use App\Support\BusinessTime;
use App\Support\History\History;
use Illuminate\Validation\ValidationException;

final class UndoCheckIn extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $booking = FrontDeskLock::acquire($booking);

            if ($booking->status !== BookingStatus::InHouse || $booking->checked_in_at === null) {
                throw ValidationException::withMessages([
                    'status' => ['Only an in-house booking can have its check-in undone.'],
                ]);
            }

            $checkedInOn = BusinessTime::toBusiness($booking->checked_in_at)->toDateString();

            if ($checkedInOn !== BusinessTime::now()->toDateString()) {
                throw ValidationException::withMessages([
                    'checked_in_at' => ['Check-in can be undone only on the same day.'],
                ]);
            }

            $reason = isset($data['reason']) && is_string($data['reason']) ? trim($data['reason']) : '';

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => ['A reason is required to undo a check-in.'],
                ]);
            }

            $from = $booking->status;
            $previous = $this->previousStatus($booking);
            $booking->status = $previous;
            $booking->checked_in_at = null;
            $booking->save();

            History::record($booking, 'booking.check_in_undone', before: [
                'status' => $from->value,
            ], after: [
                'status' => $previous->value,
                'checked_in_at' => null,
                'what' => 'Check-in undone',
            ], reason: $reason, actor: $actor);

            BookingStatusChanged::dispatch($booking, $from, $previous);

            return $booking->refresh()->load(['room', 'roomType', 'property', 'contact', 'owner']);
        });
    }

    private function previousStatus(Booking $booking): BookingStatus
    {
        $entry = ChangeHistory::query()
            ->where('subject_type', $booking->getMorphClass())
            ->where('subject_id', $booking->id)
            ->where('event', 'booking.checked_in')
            ->orderByDesc('id')
            ->first();

        $status = $entry?->before['status'] ?? null;

        if (! is_string($status) || BookingStatus::tryFrom($status) === null) {
            throw ValidationException::withMessages([
                'status' => ['The previous status is not on the check-in history.'],
            ]);
        }

        return BookingStatus::from($status);
    }
}
