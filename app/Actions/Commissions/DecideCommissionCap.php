<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Actions\Action;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Support\Bookings\FrontDeskLock;
use App\Support\History\History;
use App\Support\Payments\ApplyPaymentEffects;
use Illuminate\Validation\ValidationException;

final class DecideCommissionCap extends Action
{
    public function __construct(private readonly ApplyPaymentEffects $effects) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $booking = FrontDeskLock::acquire($booking);

            if ($booking->agency_id === null || $booking->commission_pct === null) {
                throw ValidationException::withMessages([
                    'approve' => ['This booking has no commission to decide.'],
                ]);
            }

            $approve = (bool) $data['approve'];
            $reason = trim((string) $data['reason']);

            if ($approve) {
                if ($booking->commission_approved) {
                    throw ValidationException::withMessages([
                        'approve' => ['This commission is already approved.'],
                    ]);
                }

                $booking->commission_approved = true;
                $booking->commission_approved_by = $actor->id;
                $booking->commission_approved_at = now();
                $booking->commission_reason = $reason;
                $booking->save();

                History::record($booking, 'booking.commission_approved', before: [
                    'commission_approved' => false,
                ], after: [
                    'commission_approved' => true,
                    'commission_pct' => $booking->commission_pct,
                ], reason: $reason, actor: $actor);

                $latest = $booking->payments()->orderByDesc('id')->first();

                if ($latest instanceof Payment) {
                    $booking = $this->effects->handle($booking, $latest);
                }

                return $booking->refresh()->load([
                    'property',
                    'room',
                    'contact',
                    'group.coordinator',
                    'owner',
                    'ratesVersion',
                    'agency',
                ]);
            }

            $booking->commission_reason = $reason;
            $booking->save();

            History::record($booking, 'booking.commission_rejected', before: [
                'commission_approved' => $booking->commission_approved,
            ], after: [
                'commission_approved' => false,
                'commission_pct' => $booking->commission_pct,
            ], reason: $reason, actor: $actor);

            return $booking->refresh()->load([
                'property',
                'room',
                'contact',
                'group.coordinator',
                'owner',
                'ratesVersion',
                'agency',
            ]);
        });
    }
}
