<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Action;
use App\Enums\PaymentStatus;
use App\Events\PaymentSettled;
use App\Models\Payment;
use App\Models\User;
use App\Support\Bookings\FrontDeskLock;
use App\Support\BusinessTime;
use App\Support\History\History;
use App\Support\Payments\ApplyPaymentEffects;
use App\Support\Payments\Ledger;
use App\Support\Payments\PaymentHistory;
use Illuminate\Validation\ValidationException;

final class MarkWireReceived extends Action
{
    public function __construct(private readonly ApplyPaymentEffects $effects) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Payment $payment, array $data, User $actor): Payment
    {
        return $this->transaction(function () use ($payment, $data, $actor): Payment {
            $payment->loadMissing('booking');
            $booking = FrontDeskLock::acquire($payment->booking);
            $payment = $payment->fresh() ?? $payment;

            if ($payment->status !== PaymentStatus::AwaitingWire) {
                throw ValidationException::withMessages([
                    'payment' => ['Only an awaiting wire can be marked received.'],
                ]);
            }

            $bankReference = trim((string) $data['bank_reference']);

            $payment->status = PaymentStatus::Settled;
            $payment->gateway_id = $bankReference;
            $payment->paid_at = BusinessTime::now();
            $payment->save();

            Ledger::forgetAggregates($booking);

            History::record($booking, PaymentHistory::SETTLED, after: [
                ...PaymentHistory::recordedPayload($payment),
                'bank_reference' => $bankReference,
            ], actor: $actor);

            $this->effects->handle($booking, $payment);

            if ($payment->amount > 0) {
                PaymentSettled::dispatch($booking, $payment);
            }

            return $payment->refresh()->load(['booking', 'recordedBy']);
        });
    }
}
