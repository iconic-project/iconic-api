<?php

declare(strict_types=1);

namespace App\Support\Offers;

use App\Enums\BookingSegment;
use App\Enums\CabinCategory;
use App\Models\Departure;
use App\Models\Offer;

final class PromoCode
{
    /**
     * @return array{valid: bool, reason: string|null, offer: Offer|null}
     */
    public static function check(
        string $code,
        Departure $departure,
        // TODO(Sprint 18): room type pricing (09 H8)
        CabinCategory $cabinType,
        BookingSegment $channel,
        string $bookingDate,
    ): array {
        $normalized = strtoupper(trim($code));

        if ($normalized === '') {
            return [
                'valid' => false,
                'reason' => 'This code is not valid',
                'offer' => null,
            ];
        }

        $offer = Offer::query()
            ->where('is_promo_code', true)
            ->whereRaw('UPPER(code) = ?', [$normalized])
            ->first();

        if (! $offer instanceof Offer) {
            return [
                'valid' => false,
                'reason' => 'This code is not valid',
                'offer' => null,
            ];
        }

        if ($departure->festive) {
            return [
                'valid' => false,
                'reason' => 'This code does not apply to festive departures',
                'offer' => $offer,
            ];
        }

        $applicable = Offer::applicableTo($departure, $cabinType, $channel, $bookingDate, $normalized)
            ->first(fn (Offer $item): bool => $item->id === $offer->id);

        if (! $applicable instanceof Offer) {
            return [
                'valid' => false,
                'reason' => 'This code is not valid',
                'offer' => $offer,
            ];
        }

        return [
            'valid' => true,
            'reason' => null,
            'offer' => $offer,
        ];
    }
}
