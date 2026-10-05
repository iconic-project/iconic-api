<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Actions\Action;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundRequestStatus;
use App\Models\RefundRequest;
use App\Models\User;
use App\Support\Bookings\FrontDeskLock;
use App\Support\History\History;
use App\Support\Money;
use App\Support\Payments\InsertLedgerRow;
use Illuminate\Validation\ValidationException;

final class ExecuteRefund extends Action
{
    public function __construct(private readonly InsertLedgerRow $insert) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(RefundRequest $refund, array $data, User $actor): RefundRequest
    {
        return $this->transaction(function () use ($refund, $data, $actor): RefundRequest {
            $refund = RefundRequest::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $refund->loadMissing('booking');

            if ($refund->status === RefundRequestStatus::Executed) {
                throw ValidationException::withMessages([
                    'amount' => ['This refund has already been executed.'],
                ]);
            }

            if ($refund->status !== RefundRequestStatus::Approved) {
                throw ValidationException::withMessages([
                    'amount' => ['This refund has not been approved.'],
                ]);
            }

            $amount = array_key_exists('amount', $data) && $data['amount'] !== null
                ? (int) $data['amount']
                : $refund->refund_due;

            if ($amount !== $refund->refund_due) {
                throw ValidationException::withMessages([
                    'amount' => ['The amount must equal the refund due.'],
                ]);
            }

            $method = $data['method'] instanceof PaymentMethod
                ? $data['method']
                : PaymentMethod::from((string) $data['method']);

            $reference = isset($data['reference']) && is_string($data['reference']) && $data['reference'] !== ''
                ? $data['reference']
                : null;

            $booking = FrontDeskLock::acquire($refund->booking);

            $payment = $this->insert->handle($booking, [
                'kind' => PaymentKind::Refund,
                'method' => $method,
                'amount' => -$amount,
                'status' => PaymentStatus::Refunded,
                'gateway_id' => $reference,
                'recorded_by' => $actor->id,
            ]);

            $refund->status = RefundRequestStatus::Executed;
            $refund->executed_payment_id = $payment->id;
            $refund->save();

            History::record($booking, 'refund.executed', after: [
                'amount' => $amount,
                'payment_id' => $payment->id,
                'what' => 'Refund executed — '.Money::format($amount).' ('.$refund->penalty_pct.' % penalty band)',
            ], reason: 'Director approval', actor: $actor);

            return $refund->fresh(['booking.contact', 'booking.departure', 'decidedBy', 'executedPayment']) ?? $refund;
        });
    }
}
