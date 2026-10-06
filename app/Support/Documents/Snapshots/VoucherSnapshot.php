<?php

declare(strict_types=1);

namespace App\Support\Documents\Snapshots;

use App\Enums\DocumentKind;
use App\Models\Booking;

final class VoucherSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Booking $booking, bool $fresh): array
    {
        $facts = DocumentFacts::load($booking, $fresh);
        $issuer = $facts->issuer();
        $services = $booking->extras->pluck('name')->filter(
            fn (mixed $name): bool => is_string($name) && $name !== '',
        )->values()->all();

        return [
            'document' => $facts->document(
                DocumentKind::Voucher->value,
                'TRANSFER VOUCHER',
                $facts->nextVersion(DocumentKind::Voucher->value),
            ),
            'reference' => $booking->displayReference(),
            'guests' => $facts->guestNames() === [] ? [$booking->contact->name] : $facts->guestNames(),
            'arrival' => $facts->shortDate($booking->stay()->checkIn()),
            'transfer' => $services === [] ? 'Arrival transfer' : implode(' · ', $services),
            'services' => $services,
            'footer' => [
                'email' => $issuer['email'],
                'website' => $issuer['website'],
            ],
        ];
    }
}
