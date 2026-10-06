<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Action;
use App\Enums\BookingStatus;
use App\Enums\OverdueDecision;
use App\Models\Booking;
use App\Models\User;
use App\Support\Bookings\FrontDeskLock;
use App\Support\BusinessTime;
use App\Support\History\History;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class DecideOverdue extends Action
{
    public function __construct(private readonly TransitionBooking $transitions) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $booking = FrontDeskLock::acquire($booking);

            if (! $booking->isOverdue()) {
                throw ValidationException::withMessages([
                    'decision' => ['This booking is not overdue.'],
                ]);
            }

            $decision = $data['decision'] instanceof OverdueDecision
                ? $data['decision']
                : OverdueDecision::from((string) $data['decision']);
            $reason = trim((string) $data['reason']);

            if ($decision === OverdueDecision::Extend) {
                return $this->extend($booking, $data, $actor, $reason);
            }

            return $this->transitions->handle($booking, [
                'to' => BookingStatus::Cancelled,
                'reason' => $reason,
                'what' => 'OPS-007 decision — cancelled per policy · OVERDUE → CANCELLED',
            ], $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extend(Booking $booking, array $data, User $actor, string $reason): Booking
    {
        $today = BusinessTime::now()->toDateString();
        $arrival = $booking->stay()->checkIn()->toDateString();
        $newDue = (string) $data['new_due_date'];

        if ($newDue <= $today || $newDue > $arrival) {
            throw ValidationException::withMessages([
                'new_due_date' => ['The new due date must be a future Galápagos date and not after arrival.'],
            ]);
        }

        $before = $booking->balanceDueDate()->toDateString();
        $override = CarbonImmutable::createFromFormat('!Y-m-d', $newDue);

        if (! $override instanceof CarbonImmutable) {
            throw ValidationException::withMessages([
                'new_due_date' => ['The new due date must be a future Galápagos date and not after arrival.'],
            ]);
        }

        $booking->balance_due_date_override = $override;
        $booking->save();

        History::record($booking, 'booking.overdue_extended', before: [
            'balance_due_date' => $before,
        ], after: [
            'balance_due_date' => $newDue,
            'what' => 'OPS-007 decision — extension granted · OVERDUE → CONFIRMED',
        ], reason: $reason, actor: $actor);

        return $booking->refresh()->load([
            'property',
            'room',
            'contact',
            'group.coordinator',
            'owner',
            'ratesVersion',
        ]);
    }
}
