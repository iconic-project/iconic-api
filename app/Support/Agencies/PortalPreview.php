<?php

declare(strict_types=1);

namespace App\Support\Agencies;

use App\Enums\CommissionAccrualStatus;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\CommissionPayout;
use App\Models\Guest;
use App\Services\Config\CurrentConfig;
use App\Support\Commissions\Accrual;
use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Portal\PortalStayRates;
use App\Support\Rounding;

final class PortalPreview
{
    /** @var list<string> */
    public const MATERIALS = [
        'Fact sheet',
        'Brand deck',
        'High-res photography',
        'Property PDFs',
        'Video',
    ];

    public const MATERIALS_NOTE = 'assets pending upload';

    /**
     * Year tables are gone. Hotel net rates are stay_rates on the preview.
     *
     * @return array{commission_pct: int, net_rates: list<empty>}
     */
    public static function for(Agency $agency, RatesDocument $rates): array
    {
        return [
            'commission_pct' => $agency->commission_pct,
            'net_rates' => [],
        ];
    }

    /**
     * What the agency will see. Public rates are not included.
     * Net due follows the prototype: balance × (100 − frozen commission %) / 100.
     *
     * @return array{
     *     commission_pct: int,
     *     net_rates: list<empty>,
     *     bookings: list<array{reference: string|null, lead_guest: string, check_in: string, check_out: string, departure_date: string, status: string, net_due: int}>,
     *     commissions: list<array{reference: string|null, check_in: string, check_out: string, rate: int|null, commission_amount: int, payable_date: string, status: CommissionAccrualStatus, payout: array{paid_on: string, reference: string|null}|null}>,
     *     stay_rates: array<string, mixed>,
     *     sales_materials: array{items: list<string>, note: string}
     * }
     */
    public static function view(Agency $agency, RatesDocument $rates, BusinessRulesDocument $rules): array
    {
        $agency->loadMissing([
            'bookings.property',
            'bookings.contact',
            'bookings.guests',
            'bookings.commissionPayout',
        ]);

        $preview = self::for($agency, $rates);
        $bookings = [];
        $commissions = [];

        foreach ($agency->bookings as $booking) {
            $stay = $booking->stay();
            $bookings[] = [
                'reference' => $booking->reference,
                'lead_guest' => self::leadGuestName($booking),
                'check_in' => $stay->checkIn()->toDateString(),
                'check_out' => $stay->checkOut()->toDateString(),
                'departure_date' => $stay->checkIn()->toDateString(),
                'status' => $booking->status->value,
                'net_due' => self::netDue($booking),
            ];
            $commissions[] = [
                'reference' => self::nullableString($booking->reference),
                'check_in' => $stay->checkIn()->toDateString(),
                'check_out' => $stay->checkOut()->toDateString(),
                'rate' => self::nullableInt($booking->commission_pct),
                'commission_amount' => $booking->commissionAmount(),
                'payable_date' => Accrual::payableDate($booking, $rules)->toDateString(),
                'status' => Accrual::status($booking, $rules),
                'payout' => $booking->commissionPayout instanceof CommissionPayout ? [
                    'paid_on' => $booking->commissionPayout->paid_on->toDateString(),
                    'reference' => $booking->reference,
                ] : null,
            ];
        }

        return [
            'commission_pct' => $preview['commission_pct'],
            'net_rates' => $preview['net_rates'],
            'stay_rates' => PortalStayRates::document($agency, $rates, app(CurrentConfig::class)),
            'bookings' => $bookings,
            'commissions' => $commissions,
            'sales_materials' => [
                'items' => self::MATERIALS,
                'note' => self::MATERIALS_NOTE,
            ],
        ];
    }

    private static function nullableString(?string $value): ?string
    {
        return $value;
    }

    private static function nullableInt(?int $value): ?int
    {
        return $value;
    }

    public static function leadGuestName(Booking $booking): string
    {
        $named = fn (Guest $guest): bool => $guest->first_name !== '' || $guest->last_name !== '';
        $lead = $booking->guests->first(fn (Guest $guest): bool => $guest->is_lead && $named($guest))
            ?? $booking->guests->first($named);

        return $lead instanceof Guest ? $lead->displayName() : $booking->contact->name;
    }

    /**
     * Prototype formula. Commission is on the room total, so this also discounts
     * extras and fees, and it drifts once part of the balance has been paid.
     */
    public static function netDue(Booking $booking): int
    {
        $pct = $booking->commission_pct ?? 0;

        return Rounding::halfUp($booking->balance() * (100 - $pct) / 100);
    }
}
