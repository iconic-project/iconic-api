<?php

declare(strict_types=1);

namespace App\Services\Engine;

use App\Enums\BookingType;
use App\Enums\OfferChannel;
use App\Enums\OfferStatus;
use App\Models\Departure;
use App\Models\Offer;
use App\Services\Pricing\ReservationQuoter;
use App\Support\Bookings\SoldOn;
use App\Support\IpHash;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

final class EnginePromoCheck
{
    public function __construct(private ReservationQuoter $quoter) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{valid: bool, reason: string|null, line: string|null, applies_to: list<string>}
     */
    public function check(array $input, Departure $departure, ?string $ip): array
    {
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $quote = $this->quoter->quote([
            'departure_id' => $departure->id,
            'type' => BookingType::Cabin->value,
            'cabins' => $input['cabins'] ?? [],
            'promo_code' => $code,
        ], $departure);

        $appliesTo = [];

        foreach ($quote->parties as $party) {
            if ($party->quote === null) {
                continue;
            }

            foreach ($party->quote->lines as $line) {
                if (strcasecmp($line->code, $code) === 0) {
                    $appliesTo[] = $party->cabinLabel;
                    break;
                }
            }
        }

        if ($appliesTo !== []) {
            $offer = Offer::query()
                ->where('is_promo_code', true)
                ->whereRaw('UPPER(code) = ?', [$code])
                ->first();

            return [
                'valid' => true,
                'reason' => null,
                'line' => $offer instanceof Offer ? $offer->price_line : null,
                'applies_to' => $appliesTo,
            ];
        }

        $reason = $departure->festive
            ? 'This code does not apply to festive departures'
            : 'This code is not valid';

        Log::info('engine.promo.invalid', [
            'ip_hash' => IpHash::of($ip),
            'departure_id' => $departure->id,
            'reason' => $reason,
        ]);

        return [
            'valid' => false,
            'reason' => $reason,
            'line' => null,
            'applies_to' => [],
        ];
    }

    /**
     * TODO(Sprint 22): H23 applies an offer per night inside the stay window,
     * with min_nights. Until that migration, check_in is the travel date.
     *
     * @return array{valid: bool, reason: string|null, line: string|null, applies_to: list<string>}
     */
    public function checkStay(string $code, string $checkIn, ?string $roomType, ?string $ip): array
    {
        $normalized = strtoupper(trim($code));
        $offer = Offer::query()
            ->where('is_promo_code', true)
            ->whereRaw('UPPER(code) = ?', [$normalized])
            ->first();

        $invalid = [
            'valid' => false,
            'reason' => 'This code is not valid',
            'line' => null,
            'applies_to' => [],
        ];

        if (! $offer instanceof Offer || $offer->status !== OfferStatus::Live || $offer->isDerivedExpired()) {
            $this->logInvalid($ip, $checkIn, 'This code is not valid');

            return $invalid;
        }

        if (! in_array($offer->channel, [OfferChannel::D2C, OfferChannel::All], true)) {
            $this->logInvalid($ip, $checkIn, 'This code is not valid');

            return $invalid;
        }

        $today = SoldOn::today();

        if ($this->outside($offer->booking_from, $offer->booking_to, $today)
            || $this->outside($offer->travel_from, $offer->travel_to, $checkIn)) {
            $this->logInvalid($ip, $checkIn, 'This code is not valid');

            return $invalid;
        }

        return [
            'valid' => true,
            'reason' => null,
            'line' => $offer->price_line,
            'applies_to' => is_string($roomType) && $roomType !== '' ? [$roomType] : [],
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

    private function outside(mixed $from, mixed $to, string $date): bool
    {
        if ($from instanceof CarbonInterface && $from->toDateString() > $date) {
            return true;
        }

        if ($to instanceof CarbonInterface && $to->toDateString() < $date) {
            return true;
        }

        return false;
    }
}
