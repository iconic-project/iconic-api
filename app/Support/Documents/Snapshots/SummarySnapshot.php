<?php

declare(strict_types=1);

namespace App\Support\Documents\Snapshots;

use App\Enums\DocumentKind;
use App\Models\Booking;

final class SummarySnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Booking $booking, bool $fresh): array
    {
        $facts = DocumentFacts::load($booking, $fresh);
        $issuer = $facts->issuer();
        $stay = $facts->stay();

        return [
            'document' => $facts->document(
                DocumentKind::Summary->value,
                'BOOKING SUMMARY',
                $facts->nextVersion(DocumentKind::Summary->value),
            ),
            'reference' => $booking->displayReference(),
            'date' => $facts->invoiceDate(),
            'status_line' => $facts->statusLine(),
            'lead_name' => $facts->leadName(),
            'stay' => $stay,
            'totals' => $facts->totals(),
            'schedule' => $facts->schedule(),
            'balance_due_date' => $facts->shortDate($booking->balanceDueDate()),
            'deposit_received' => $facts->depositReceived(),
            'footer' => [
                'email' => $issuer['email'],
                'website' => $issuer['website'],
            ],
        ];
    }
}
