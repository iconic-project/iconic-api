<?php

declare(strict_types=1);

namespace App\Support\Offers;

use App\Enums\BookingSegment;
use App\Models\Offer;
use App\Support\Bookings\SoldOn;
use App\Support\Stays\StayDates;

final class PromoCode
{
    /**
     * @return array{valid: bool, reason: string|null, offer: Offer|null}
     */
    public static function checkStay(
        string $code,
        StayDates $stay,
        ?string $roomType,
        ?string $ratePlan,
        BookingSegment $channel,
        ?string $bookingDate = null,
    ): array {
        $normalized = strtoupper(trim($code));
        $bookingDate ??= SoldOn::today();

        if ($normalized === '') {
            return self::invalid(null);
        }

        $offer = Offer::query()
            ->where('is_promo_code', true)
            ->whereRaw('UPPER(code) = ?', [$normalized])
            ->first();

        if (! $offer instanceof Offer) {
            return self::invalid(null);
        }

        $matched = Offer::query()
            ->forStay($stay, $roomType, $ratePlan, $channel, $bookingDate, $normalized)
            ->whereKey($offer->id)
            ->first();

        if (! $matched instanceof Offer || $matched->isDerivedExpired($bookingDate)) {
            return self::invalid($offer);
        }

        return [
            'valid' => true,
            'reason' => null,
            'offer' => $matched,
        ];
    }

    /**
     * @return array{valid: bool, reason: string|null, offer: Offer|null}
     */
    private static function invalid(?Offer $offer): array
    {
        return [
            'valid' => false,
            'reason' => 'This code is not valid',
            'offer' => $offer,
        ];
    }
}
