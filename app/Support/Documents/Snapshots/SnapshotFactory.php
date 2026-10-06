<?php

declare(strict_types=1);

namespace App\Support\Documents\Snapshots;

use App\Enums\DocumentKind;
use App\Models\Booking;
use App\Models\Payment;
use InvalidArgumentException;

final class SnapshotFactory
{
    /**
     * @return array<string, mixed>
     */
    public static function build(
        Booking $booking,
        DocumentKind $kind,
        ?Payment $payment = null,
        bool $fresh = false,
    ): array {
        return match ($kind) {
            DocumentKind::Invoice => InvoiceSnapshot::build($booking, false, $fresh),
            DocumentKind::FinalInvoice => InvoiceSnapshot::build($booking, true, $fresh),
            DocumentKind::Summary => SummarySnapshot::build($booking, $fresh),
            DocumentKind::Receipt => self::receipt($booking, $payment, $fresh),
            DocumentKind::Voucher => VoucherSnapshot::build($booking, $fresh),
            DocumentKind::PreArrival => PreArrivalSnapshot::build($booking, $fresh),
            DocumentKind::Pretrip => PreArrivalSnapshot::build($booking, $fresh, DocumentKind::Pretrip),
            DocumentKind::WireInstructions => WireInstructionsSnapshot::build($booking, $fresh),
            DocumentKind::CharterProposal => throw new InvalidArgumentException('A charter proposal is not built from a booking.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function receipt(Booking $booking, ?Payment $payment, bool $fresh): array
    {
        if (! $payment instanceof Payment) {
            throw new InvalidArgumentException('A receipt needs a payment.');
        }

        return ReceiptSnapshot::build($booking, $payment, $fresh);
    }
}
