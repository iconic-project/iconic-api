<?php

declare(strict_types=1);

namespace App\Services\Engine;

use App\Enums\BookingSegment;
use App\Models\Offer;
use App\Support\Bookings\SoldOn;
use App\Support\IpHash;
use App\Support\Offers\PromoCode;
use App\Support\Stays\StayDates;
use Illuminate\Support\Facades\Log;

final class EnginePromoCheck
{
    /**
     * @return array{valid: bool, reason: string|null, line: string|null, applies_to: list<string>}
     */
    public function checkStay(
        string $code,
        string $checkIn,
        string $checkOut,
        ?string $roomType,
        ?string $ratePlan,
        ?string $ip,
    ): array {
        $invalid = [
            'valid' => false,
            'reason' => 'This code is not valid',
            'line' => null,
            'applies_to' => [],
        ];

        $stay = StayDates::of($checkIn, $checkOut);
        $check = PromoCode::checkStay(
            $code,
            $stay,
            $roomType,
            $ratePlan,
            BookingSegment::D2C,
            SoldOn::today(),
        );

        if (! $check['valid'] || ! $check['offer'] instanceof Offer) {
            $this->logInvalid($ip, $checkIn, 'This code is not valid');

            return $invalid;
        }

        $offer = $check['offer'];
        $appliesTo = [];

        if (is_string($roomType) && $roomType !== '') {
            $appliesTo = [$roomType];
        }

        return [
            'valid' => true,
            'reason' => null,
            'line' => $offer->price_line,
            'applies_to' => $appliesTo,
        ];
    }

    private function logInvalid(?string $ip, string $checkIn, string $reason): void
    {
        Log::info('engine.promo.invalid', [
            'ip_hash' => IpHash::of($ip),
            'check_in' => $checkIn,
            'reason' => $reason,
        ]);
    }
}
