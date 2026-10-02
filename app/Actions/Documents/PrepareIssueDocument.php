<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Action;
use App\Enums\DocumentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Document;
use App\Models\Payment;
use App\Models\User;
use App\Support\Bookings\BookingMutationLock;
use App\Support\Documents\Snapshots\DocumentFacts;
use App\Support\Documents\Snapshots\SnapshotFactory;
use Illuminate\Validation\ValidationException;

final class PrepareIssueDocument extends Action
{
    public function __construct(private readonly IssueDocument $issuer) {}

    public function handle(
        Booking $booking,
        DocumentKind $kind,
        ?string $reason = null,
        ?Payment $payment = null,
        ?User $actor = null,
        bool $system = false,
    ): Document {
        return $this->transaction(function () use ($booking, $kind, $reason, $payment, $actor, $system): Document {
            $locked = BookingMutationLock::acquire($booking, (int) $booking->departure_id);
            $locked->load([
                'departure.property',
                'departure.itinerary',
                'cabin',
                'contact',
                'group.coordinator',
                'agency',
                'guests',
                'extras',
                'payments',
            ]);

            $this->assertKind($locked, $kind, $payment);

            $snapshot = SnapshotFactory::build($locked, $kind, $payment, true);

            return $this->issuer->handle(
                $locked,
                $kind,
                $snapshot,
                $reason,
                $payment,
                $actor,
                $system,
            );
        });
    }

    private function assertKind(Booking $booking, DocumentKind $kind, ?Payment $payment): void
    {
        if ($kind === DocumentKind::Voucher && ! DocumentFacts::load($booking, true)->hasTransferVoucherExtra()) {
            throw ValidationException::withMessages([
                'kind' => ['A transfer voucher needs a contracted extra that triggers one.'],
            ]);
        }

        if ($kind !== DocumentKind::Receipt) {
            return;
        }

        if (! $payment instanceof Payment) {
            throw ValidationException::withMessages([
                'payment_id' => ['A receipt needs a payment.'],
            ]);
        }

        if ((int) $payment->booking_id !== (int) $booking->id) {
            throw ValidationException::withMessages([
                'payment_id' => ['The payment does not belong to this booking.'],
            ]);
        }

        if ($payment->status !== PaymentStatus::Settled || $payment->amount <= 0) {
            throw ValidationException::withMessages([
                'payment_id' => ['A receipt is only issued for a settled positive payment.'],
            ]);
        }
    }
}
