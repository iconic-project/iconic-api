<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Action;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\PaymentAwaitingWire;
use App\Events\PaymentSettled;
use App\Models\Booking;
use App\Models\User;
use App\Support\Bookings\FrontDeskLock;
use App\Support\History\History;
use App\Support\Money;
use App\Support\Payments\ApplyPaymentEffects;
use App\Support\Payments\InsertLedgerRow;
use App\Support\Payments\Ledger;
use App\Support\Payments\PaymentHistory;
use App\Support\Payments\RecordedPayment;

final class RecordPayment extends Action
{
    public function __construct(
        private readonly InsertLedgerRow $insert,
        private readonly ApplyPaymentEffects $effects,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Booking $booking, array $data, User $actor): RecordedPayment
    {
        return $this->transaction(function () use ($booking, $data, $actor): RecordedPayment {
            $booking = FrontDeskLock::acquire($booking);

            $kind = $data['kind'] instanceof PaymentKind
                ? $data['kind']
                : PaymentKind::from((string) $data['kind']);
            $method = $data['method'] instanceof PaymentMethod
                ? $data['method']
                : PaymentMethod::from((string) $data['method']);

            $status = $this->status($method, $data['status'] ?? null);
            $amount = (int) $data['amount'];
            $paid = Ledger::paidFresh($booking);
            $warnings = [];

            $charges = $booking->chargesTotalFresh();

            if ($status->countsAsPaid() && ($paid + $amount) > $charges) {
                $warnings[] = 'This takes the booking above its total by '
                    .Money::format($paid + $amount - $charges);
            }

            $payment = $this->insert->handle($booking, [
                'kind' => $kind,
                'method' => $method,
                'amount' => $amount,
                'status' => $status,
                'paid_at' => $data['paid_at'] ?? null,
                'note' => $data['note'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            History::record($booking, PaymentHistory::RECORDED, after: PaymentHistory::recordedPayload($payment), actor: $actor);

            $booking = $this->effects->handle($booking, $payment);

            if ($payment->status === PaymentStatus::Settled && $payment->amount > 0) {
                PaymentSettled::dispatch($booking, $payment);
            }

            if ($payment->status === PaymentStatus::AwaitingWire) {
                PaymentAwaitingWire::dispatch($payment);
            }

            return new RecordedPayment($payment, $booking, $warnings);
        });
    }

    private function status(PaymentMethod $method, mixed $explicit): PaymentStatus
    {
        if ($explicit instanceof PaymentStatus) {
            return $explicit;
        }

        if (is_string($explicit) && $explicit !== '') {
            return PaymentStatus::from($explicit);
        }

        return $method === PaymentMethod::Wire
            ? PaymentStatus::AwaitingWire
            : PaymentStatus::Settled;
    }
}
