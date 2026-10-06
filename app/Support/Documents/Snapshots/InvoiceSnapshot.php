<?php

declare(strict_types=1);

namespace App\Support\Documents\Snapshots;

use App\Enums\DocumentKind;
use App\Models\Booking;

final class InvoiceSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Booking $booking, bool $final, bool $fresh): array
    {
        $facts = DocumentFacts::load($booking, $fresh);
        $kind = $final ? DocumentKind::FinalInvoice : DocumentKind::Invoice;
        $title = $final ? 'FINAL INVOICE' : 'BOOKING CONFIRMATION & INVOICE';
        $fees = $facts->feeRows();
        $issuer = $facts->issuer();

        return [
            'document' => $facts->document($kind->value, $title, $facts->nextVersion($kind->value)),
            'reference' => $booking->displayReference(),
            'date' => $facts->invoiceDate(),
            'balance_due_date' => $facts->shortDate($booking->balanceDueDate()),
            'status_line' => $final ? 'PAID IN FULL' : $facts->statusLine(),
            'issuer' => $issuer,
            'billing' => $facts->billing(),
            'stay' => $facts->stay(),
            'vessel_rows' => $facts->vesselRows(),
            'fees' => $fees,
            'extras_rows' => $facts->extrasRows(),
            'totals' => $facts->totals(),
            'schedule' => $facts->schedule(),
            'insurance' => DocumentFacts::INSURANCE,
            'payments' => $facts->paymentRows(),
            'bank' => $facts->bank(),
            'footer' => [
                'email' => $issuer['email'],
                'website' => $issuer['website'],
            ],
        ];
    }
}
