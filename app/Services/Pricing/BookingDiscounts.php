<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\BookingSegment;
use App\Enums\CabinCategory;
use App\Enums\OfferType;
use App\Models\Departure;
use App\Models\Offer;
use App\Services\Config\CurrentConfig;
use App\Support\Offers\PromoCode;
use App\Support\Rounding;

final class BookingDiscounts
{
    public function __construct(private CurrentConfig $config) {}

    /**
     * @return array{quote: Quote, warnings: list<string>}
     */
    public function apply(
        Quote $cabin,
        Departure $departure,
        // TODO(Sprint 18): room type pricing (09 H8)
        CabinCategory $category,
        BookingSegment $channel,
        string $bookingDate,
        int $adults,
        int $children,
        bool $onlineDeposit,
        ?string $promoCode,
    ): array {
        $step1 = $cabin->total;
        $guests = $adults + $children;
        $warnings = [];

        $offers = Offer::applicableTo($departure, $category, $channel, $bookingDate)
            ->filter(fn (Offer $offer): bool => $offer->type !== OfferType::Commission)
            ->values();

        $zeroLines = [];
        $priceOffers = [];

        foreach ($offers as $offer) {
            if (in_array($offer->type, [OfferType::Credit, OfferType::Value], true)) {
                $zeroLines[] = new QuoteLine(
                    $offer->code,
                    $this->offerLabel($offer),
                    0,
                );

                continue;
            }

            $priceOffers[] = $offer;
        }

        $promo = null;

        if ($promoCode !== null && trim($promoCode) !== '') {
            $check = PromoCode::check($promoCode, $departure, $category, $channel, $bookingDate);

            if ($check['valid'] && $check['offer'] instanceof Offer) {
                $promo = $check['offer'];
            } elseif (is_string($check['reason'])) {
                $warnings[] = $check['reason'];
            }
        }

        $onlinePct = $this->config->businessRules()->discounts->onlineDepositDiscountPct;
        $onlineLabel = $this->config->engineSettings()->copy->onlineDepositAdvantage.' −'.$onlinePct.'%';

        $result = $this->chooseStack(
            $priceOffers,
            $onlineDeposit && $onlinePct > 0,
            $onlinePct,
            $onlineLabel,
            $promo,
            $step1,
            $guests,
        );

        $warnings = [...$warnings, ...$result['warnings']];
        $priceLines = $this->capLast($result['lines'], $step1);

        $lines = [...$cabin->lines, ...$zeroLines, ...$priceLines];
        $total = $step1;

        foreach ([...$zeroLines, ...$priceLines] as $line) {
            $total += $line->amount;
        }

        if ($total < 0) {
            $total = 0;
        }

        return [
            'quote' => new Quote(
                $lines,
                $total,
                $cabin->depositPct,
                Rounding::halfUp($total * $cabin->depositPct / 100),
            ),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<Offer>  $priceOffers
     * @return array{lines: list<QuoteLine>, warnings: list<string>}
     */
    private function chooseStack(
        array $priceOffers,
        bool $includeOnline,
        int $onlinePct,
        string $onlineLabel,
        ?Offer $promo,
        int $step1,
        int $guests,
    ): array {
        $combinableOffers = [];
        $nonCombinableOffers = [];

        foreach ($priceOffers as $offer) {
            if ($offer->combinable) {
                $combinableOffers[] = $offer;
            } else {
                $nonCombinableOffers[] = $offer;
            }
        }

        $promoCombinable = $promo === null || $promo->combinable;
        $candidates = [];

        $combinableStack = $this->buildLines(
            $combinableOffers,
            $includeOnline,
            $onlinePct,
            $onlineLabel,
            $promoCombinable ? $promo : null,
            $step1,
            $guests,
        );
        $candidates[] = [
            'lines' => $combinableStack,
            'kept' => $this->stackLabel($combinableOffers, $includeOnline, $promoCombinable ? $promo : null),
        ];

        foreach ($nonCombinableOffers as $offer) {
            $candidates[] = [
                'lines' => $this->buildLines([$offer], false, $onlinePct, $onlineLabel, null, $step1, $guests),
                'kept' => $offer->name,
            ];
        }

        if ($promo instanceof Offer && ! $promo->combinable) {
            $candidates[] = [
                'lines' => $this->buildLines([], false, $onlinePct, $onlineLabel, $promo, $step1, $guests),
                'kept' => $promo->code,
            ];
        }

        $winner = $candidates[0];
        $winnerSaving = $this->saving($winner['lines']);

        foreach (array_slice($candidates, 1) as $candidate) {
            $saving = $this->saving($candidate['lines']);

            if ($saving > $winnerSaving) {
                $winner = $candidate;
                $winnerSaving = $saving;
            }
        }

        $warnings = [];
        $winnerCodes = array_map(fn (QuoteLine $line): string => $line->code, $winner['lines']);

        foreach ($priceOffers as $offer) {
            if (! in_array($offer->code, $winnerCodes, true) && $this->saving($this->buildLines([$offer], false, $onlinePct, $onlineLabel, null, $step1, $guests)) > 0) {
                $warnings[] = $offer->code.' cannot be combined with '.$winner['kept'].' — the larger discount was kept';
            }
        }

        if ($promo instanceof Offer && ! in_array($promo->code, $winnerCodes, true)) {
            $warnings[] = $promo->code.' cannot be combined with '.$winner['kept'].' — the larger discount was kept';
        }

        if ($includeOnline && ! in_array('online_deposit', $winnerCodes, true) && $winnerSaving > 0) {
            $warnings[] = 'the online deposit advantage cannot be combined with '.$winner['kept'].' — the larger discount was kept';
        }

        return [
            'lines' => $winner['lines'],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<Offer>  $offers
     * @return list<QuoteLine>
     */
    private function buildLines(
        array $offers,
        bool $includeOnline,
        int $onlinePct,
        string $onlineLabel,
        ?Offer $promo,
        int $step1,
        int $guests,
    ): array {
        $lines = [];
        $running = $step1;

        usort($offers, fn (Offer $a, Offer $b): int => $a->code <=> $b->code);

        foreach ($offers as $offer) {
            $amount = $this->offerAmount($offer, $step1, $guests, $running);

            if ($amount === 0) {
                continue;
            }

            $lines[] = new QuoteLine($offer->code, $this->offerLabel($offer), -$amount);
            $running -= $amount;
        }

        if ($includeOnline && $onlinePct > 0 && $running > 0) {
            $amount = Rounding::halfUp($running * $onlinePct / 100);
            $amount = min($amount, $running);

            if ($amount > 0) {
                $lines[] = new QuoteLine('online_deposit', $onlineLabel, -$amount);
                $running -= $amount;
            }
        }

        if ($promo instanceof Offer && $running > 0) {
            $amount = $this->offerAmount($promo, $running, $guests, $running);

            if ($amount > 0) {
                $lines[] = new QuoteLine($promo->code, $this->offerLabel($promo), -$amount);
            }
        }

        return $lines;
    }

    private function offerAmount(Offer $offer, int $base, int $guests, int $running): int
    {
        $amount = match ($offer->type) {
            OfferType::Percent => Rounding::halfUp($base * (int) $offer->value / 100),
            OfferType::Amount => $offer->is_promo_code
                ? (int) $offer->value * $guests
                : (int) $offer->value,
            default => 0,
        };

        return min($amount, $running);
    }

    private function offerLabel(Offer $offer): string
    {
        if (is_string($offer->price_line) && $offer->price_line !== '') {
            return $offer->price_line;
        }

        return (string) ($offer->value_text ?? $offer->name);
    }

    /**
     * @param  list<Offer>  $offers
     */
    private function stackLabel(array $offers, bool $includeOnline, ?Offer $promo): string
    {
        if ($promo instanceof Offer) {
            return $promo->code;
        }

        if ($offers !== []) {
            return $offers[0]->name;
        }

        if ($includeOnline) {
            return 'the online deposit advantage';
        }

        return 'the selected discount';
    }

    /**
     * @param  list<QuoteLine>  $lines
     */
    private function saving(array $lines): int
    {
        $saving = 0;

        foreach ($lines as $line) {
            $saving -= $line->amount;
        }

        return $saving;
    }

    /**
     * @param  list<QuoteLine>  $lines
     * @return list<QuoteLine>
     */
    private function capLast(array $lines, int $step1): array
    {
        $maxPct = $this->config->businessRules()->discounts->maxTotalDiscountPct;

        if ($maxPct === null || $lines === []) {
            return $lines;
        }

        $cap = Rounding::halfUp($step1 * $maxPct / 100);
        $saving = $this->saving($lines);

        if ($saving <= $cap) {
            return $lines;
        }

        $overflow = $saving - $cap;
        $last = $lines[array_key_last($lines)];
        $reduced = $last->amount + $overflow;

        if ($reduced > 0) {
            $reduced = 0;
        }

        $lines[array_key_last($lines)] = new QuoteLine(
            $last->code,
            $last->label.' — reduced to the maximum discount',
            $reduced,
        );

        return $lines;
    }
}
