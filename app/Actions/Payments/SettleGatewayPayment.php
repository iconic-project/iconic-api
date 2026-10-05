<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Action;
use App\Enums\PaymentKind;
use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\PaymentSettled;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\User;
use App\Support\Bookings\FrontDeskLock;
use App\Support\History\History;
use App\Support\Payments\ApplyPaymentEffects;
use App\Support\Payments\InsertLedgerRow;
use App\Support\Payments\PaymentHistory;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

final class SettleGatewayPayment extends Action
{
    public function __construct(
        private readonly InsertLedgerRow $insert,
        private readonly ApplyPaymentEffects $effects,
    ) {}

    /**
     * @param  array{
     *     kind: PaymentKind,
     *     method: PaymentMethod,
     *     amount: int,
     *     gateway_id: string,
     *     note?: string|null,
     *     payment_link?: PaymentLink|null
     * }  $data
     */
    public function handle(Booking $booking, array $data, ?User $actor, bool $system = false): Payment
    {
        return $this->transaction(function () use ($booking, $data, $actor, $system): Payment {
            $booking = FrontDeskLock::acquire($booking);

            $existing = Payment::query()
                ->where('gateway_id', $data['gateway_id'])
                ->first();

            if ($existing instanceof Payment) {
                $this->markLinkPaid($data['payment_link'] ?? null);

                return $existing;
            }

            try {
                $payment = $this->insert->handle($booking, [
                    'kind' => $data['kind'],
                    'method' => $data['method'],
                    'amount' => $data['amount'],
                    'status' => PaymentStatus::Settled,
                    'gateway_id' => $data['gateway_id'],
                    'note' => $data['note'] ?? null,
                    'recorded_by' => $actor?->id,
                ]);
            } catch (UniqueConstraintViolationException|QueryException $exception) {
                if (! $this->isStripeGatewayCollision($exception)) {
                    throw $exception;
                }

                $existing = Payment::query()
                    ->where('gateway_id', $data['gateway_id'])
                    ->firstOrFail();

                $this->markLinkPaid($data['payment_link'] ?? null);

                return $existing;
            }

            History::record(
                $booking,
                PaymentHistory::RECORDED,
                after: PaymentHistory::recordedPayload($payment),
                actor: $actor,
                system: $system,
            );

            $this->effects->handle($booking, $payment);
            $this->markLinkPaid($data['payment_link'] ?? null);

            if ($payment->amount > 0) {
                PaymentSettled::dispatch($booking, $payment);
            }

            return $payment;
        });
    }

    private function markLinkPaid(?PaymentLink $link): void
    {
        if (! $link instanceof PaymentLink) {
            return;
        }

        if ($link->status === PaymentLinkStatus::Paid) {
            return;
        }

        $link->status = PaymentLinkStatus::Paid;
        $link->save();
    }

    private function isStripeGatewayCollision(QueryException $exception): bool
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            return false;
        }

        return str_contains($exception->getMessage(), 'payments_stripe_gateway_id_unique')
            || str_contains($exception->getMessage(), "for key 'payments.stripe_gateway_id'");
    }
}
