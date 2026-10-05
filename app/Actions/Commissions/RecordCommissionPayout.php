<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Actions\Action;
use App\Enums\CommissionAccrualStatus;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\CommissionPayout;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\Bookings\FrontDeskLock;
use App\Support\Commissions\Accrual;
use App\Support\History\History;
use Illuminate\Validation\ValidationException;

final class RecordCommissionPayout extends Action
{
    public function __construct(private readonly CurrentConfig $config) {}

    /**
     * @param  array{amount: int, paid_on: string, bank_reference: string}  $data
     */
    public function handle(Booking $booking, array $data, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $data, $actor): Booking {
            $booking = FrontDeskLock::acquire($booking);
            $booking->load(['agency', 'departure.itinerary', 'commissionPayout']);

            $agency = $booking->agency;

            if ($booking->agency_id === null || $booking->commission_pct === null || ! $agency instanceof Agency) {
                throw ValidationException::withMessages([
                    'booking' => ['This booking has no commission to pay out.'],
                ]);
            }

            if ($booking->commissionPayout !== null) {
                throw ValidationException::withMessages([
                    'booking' => ['This booking already has a commission payout.'],
                ]);
            }

            $rules = $this->config->businessRules();

            if (Accrual::status($booking, $rules) !== CommissionAccrualStatus::Payable) {
                throw ValidationException::withMessages([
                    'booking' => ['A payout can be recorded only when the commission is PAYABLE.'],
                ]);
            }

            $expected = $booking->commissionAmount();

            if ((int) $data['amount'] !== $expected) {
                throw ValidationException::withMessages([
                    'amount' => ['The amount must equal the booking\'s commission. Partial payouts are not accepted.'],
                ]);
            }

            CommissionPayout::query()->create([
                'booking_id' => $booking->id,
                'amount' => $expected,
                'paid_on' => $data['paid_on'],
                'bank_reference' => $data['bank_reference'],
                'recorded_by' => $actor->id,
            ]);

            $after = [
                'amount' => $expected,
                'paid_on' => $data['paid_on'],
                'bank_reference' => $data['bank_reference'],
            ];

            History::record($booking, 'booking.commission_paid', after: $after, actor: $actor);
            History::record($agency, 'agency.commission_paid', after: [
                ...$after,
                'booking_reference' => $booking->reference,
            ], actor: $actor);

            return $booking->load(['agency', 'departure.itinerary', 'commissionPayout']);
        });
    }
}
