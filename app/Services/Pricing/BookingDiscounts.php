<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\OfferType;
use App\Models\Offer;
use App\Services\Config\CurrentConfig;
use App\Support\Bookings\SoldOn;
use App\Support\Offers\PromoCode;
use App\Support\Rounding;
use App\Support\Stays\StayDates;

final class BookingDiscounts
{
    public function __construct(private CurrentConfig $config) {}

    /**
     * Offer discounts apply per eligible night. The combined cap is on the stay total.
     *
     * @return array{quote: StayQuote, warnings: list<string>, pills: list<string>}
     */
    public function applyToStay(StayQuote $quote, StayQuoteInput $input): array
    {
        $step1 = $quote->total;
        $bookingDate = $input->bookingDate ?? SoldOn::today();
        $guests = $input->adults + count($input->childAges);
        $warnings = [];

        $offers = Offer::query()
            ->forStay($input->stay, $input->roomType, $input->ratePlan, $input->channel, $bookingDate)
            ->get()
            ->filter(fn (Offer $offer): bool => ! $offer->isDerivedExpired($bookingDate))
            ->filter(fn (Offer $offer): bool => $offer->type !== OfferType::Commission)
            ->values();

        $zeroLines = [];
        $priceOffers = [];

        foreach ($offers as $offer) {
            if (in_array($offer->type, [OfferType::Credit, OfferType::Value], true)) {
                $zeroLines[] = new StayQuoteLine($offer->code, 'offer', ['label' => $this->offerLabel($offer)], 0);

                continue;
            }

            $priceOffers[] = $offer;
        }

        $promo = null;

        if ($input->promo !== null && trim($input->promo) !== '') {
            $check = PromoCode::checkStay(
                $input->promo,
                $input->stay,
                $input->roomType,
                $input->ratePlan,
                $input->channel,
                $bookingDate,
            );

            if ($check['valid'] && $check['offer'] instanceof Offer) {
                $promo = $check['offer'];
            } elseif (is_string($check['reason'])) {
                $warnings[] = $check['reason'];
            }
        }

        $onlinePct = $this->config->businessRules()->discounts->onlineDepositDiscountPct;
        $onlineLabel = $this->config->engineSettings()->copy->onlineDepositAdvantage.' −'.$onlinePct.'%';

        $result = $this->chooseStayStack(
            $priceOffers,
            $input->onlineDeposit && $onlinePct > 0,
            $onlinePct,
            $onlineLabel,
            $promo,
            $quote,
            $input->stay,
            $guests,
        );

        $warnings = [...$warnings, ...$result['warnings']];
        $capped = $this->capStayLines($result['lines'], $step1);
        $nightDiscounts = $this->cappedNights($result['byOffer'], $result['lines'], $capped);
        $nightLines = [];

        foreach ($quote->nightLines as $line) {
            $nightLines[] = $line->withDiscount($nightDiscounts[$line->night] ?? 0);
        }

        $lines = [...$quote->lines, ...$zeroLines, ...$capped];
        $total = $step1;

        foreach ([...$zeroLines, ...$capped] as $line) {
            $total += $line->amount;
        }

        if ($total < 0) {
            $total = 0;
        }

        return [
            'quote' => new StayQuote(
                $nightLines,
                $lines,
                $total,
                $quote->depositPct,
                Rounding::halfUp($total * $quote->depositPct / 100),
                $quote->ratesVersionId,
                $quote->terms,
                $quote->taxLines,
            ),
            'warnings' => $warnings,
            'pills' => $this->pills($capped, $zeroLines),
        ];
    }

    /**
     * One-night from-price after automatic offers. Promo codes are not applied.
     *
     * @return array{total: int, pills: list<string>}
     */
    public function netNight(int $gross, string $night, string $roomType, string $ratePlan): array
    {
        if ($gross < 1) {
            return ['total' => $gross, 'pills' => []];
        }

        $applied = $this->applyToStay(
            new StayQuote(
                [new NightLine($night, '', $gross, 0, 0, 0, 0, 0, $gross)],
                [],
                $gross,
                0,
                0,
                null,
                new QuoteTerms(0, null),
            ),
            new StayQuoteInput(
                StayDates::forNights($night, 1),
                $roomType,
                1,
                [],
                $ratePlan,
            ),
        );

        return [
            'total' => $applied['quote']->total,
            'pills' => $applied['pills'],
        ];
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
     * @param  list<StayQuoteLine>  $lines
     * @return list<StayQuoteLine>
     */
    private function capStayLines(array $lines, int $step1): array
    {
        $maxPct = $this->config->businessRules()->discounts->maxTotalDiscountPct;

        if ($maxPct === null || $lines === []) {
            return $lines;
        }

        $cap = Rounding::halfUp($step1 * $maxPct / 100);
        $saving = 0;

        foreach ($lines as $line) {
            $saving -= $line->amount;
        }

        if ($saving <= $cap) {
            return $lines;
        }

        $overflow = $saving - $cap;
        $last = $lines[array_key_last($lines)];
        $reduced = $last->amount + $overflow;

        if ($reduced > 0) {
            $reduced = 0;
        }

        $lines[array_key_last($lines)] = new StayQuoteLine(
            $last->code,
            $last->key,
            ['label' => $last->label().' — reduced to the maximum discount'],
            $reduced,
        );

        return $lines;
    }

    /**
     * @param  list<Offer>  $priceOffers
     * @return array{lines: list<StayQuoteLine>, warnings: list<string>, byOffer: array<string, array<string, int>>}
     */
    private function chooseStayStack(
        array $priceOffers,
        bool $includeOnline,
        int $onlinePct,
        string $onlineLabel,
        ?Offer $promo,
        StayQuote $quote,
        StayDates $stay,
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

        $combinable = $this->stayLines(
            $combinableOffers,
            $includeOnline,
            $onlinePct,
            $onlineLabel,
            $promoCombinable ? $promo : null,
            $quote,
            $stay,
            $guests,
        );
        $candidates[] = [
            ...$combinable,
            'kept' => $this->stackLabel($combinableOffers, $includeOnline, $promoCombinable ? $promo : null),
        ];

        foreach ($nonCombinableOffers as $offer) {
            $built = $this->stayLines([$offer], false, $onlinePct, $onlineLabel, null, $quote, $stay, $guests);
            $candidates[] = [
                ...$built,
                'kept' => $offer->name,
            ];
        }

        if ($promo instanceof Offer && ! $promo->combinable) {
            $built = $this->stayLines([], false, $onlinePct, $onlineLabel, $promo, $quote, $stay, $guests);
            $candidates[] = [
                ...$built,
                'kept' => $promo->code,
            ];
        }

        $winner = $candidates[0];
        $winnerSaving = $this->staySaving($winner['lines']);

        foreach (array_slice($candidates, 1) as $candidate) {
            $saving = $this->staySaving($candidate['lines']);

            if ($saving > $winnerSaving) {
                $winner = $candidate;
                $winnerSaving = $saving;
            }
        }

        $warnings = [];
        $winnerCodes = array_map(fn (StayQuoteLine $line): string => $line->code, $winner['lines']);

        foreach ($priceOffers as $offer) {
            if (! in_array($offer->code, $winnerCodes, true)) {
                $alone = $this->stayLines([$offer], false, $onlinePct, $onlineLabel, null, $quote, $stay, $guests);

                if ($this->staySaving($alone['lines']) > 0) {
                    $warnings[] = $offer->code.' cannot be combined with '.$winner['kept'].' — the larger discount was kept';
                }
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
            'byOffer' => $winner['byOffer'],
        ];
    }

    /**
     * @param  list<Offer>  $offers
     * @return array{lines: list<StayQuoteLine>, byOffer: array<string, array<string, int>>}
     */
    private function stayLines(
        array $offers,
        bool $includeOnline,
        int $onlinePct,
        string $onlineLabel,
        ?Offer $promo,
        StayQuote $quote,
        StayDates $stay,
        int $guests,
    ): array {
        $remaining = [];

        foreach ($quote->nightLines as $line) {
            $remaining[$line->night] = $line->total;
        }

        $lines = [];
        $byOffer = [];

        usort($offers, fn (Offer $a, Offer $b): int => $a->code <=> $b->code);

        foreach ($offers as $offer) {
            $map = $this->allocate($offer, $quote, $stay, $remaining, $guests, true);
            $sum = array_sum($map);

            if ($sum < 1) {
                continue;
            }

            $byOffer[$offer->code] = $map;

            foreach ($map as $night => $amount) {
                $remaining[$night] -= $amount;
            }

            $lines[] = new StayQuoteLine($offer->code, 'offer', ['label' => $this->offerLabel($offer)], -$sum);
        }

        $running = array_sum($remaining);

        if ($includeOnline && $onlinePct > 0 && $running > 0) {
            $amount = min(Rounding::halfUp($running * $onlinePct / 100), $running);

            if ($amount > 0) {
                $lines[] = new StayQuoteLine('online_deposit', 'online_deposit', ['label' => $onlineLabel], -$amount);
                $running -= $amount;
            }
        }

        if ($promo instanceof Offer && $running > 0) {
            $map = $this->allocate($promo, $quote, $stay, $remaining, $guests, false);
            $sum = array_sum($map);

            if ($sum > 0) {
                $byOffer[$promo->code] = $map;
                $lines[] = new StayQuoteLine($promo->code, 'offer', ['label' => $this->offerLabel($promo)], -$sum);
            }
        }

        return [
            'lines' => $lines,
            'byOffer' => $byOffer,
        ];
    }

    /**
     * @param  array<string, int>  $remaining
     * @return array<string, int>
     */
    private function allocate(
        Offer $offer,
        StayQuote $quote,
        StayDates $stay,
        array $remaining,
        int $guests,
        bool $onOriginal,
    ): array {
        $eligible = array_fill_keys($offer->eligibleNights($stay), true);
        $map = [];

        if ($offer->type === OfferType::Percent) {
            foreach ($quote->nightLines as $line) {
                if (! isset($eligible[$line->night]) || ($remaining[$line->night] ?? 0) < 1) {
                    continue;
                }

                $base = $onOriginal ? $line->total : $remaining[$line->night];
                $cut = min(Rounding::halfUp($base * (int) $offer->value / 100), $remaining[$line->night]);

                if ($cut > 0) {
                    $map[$line->night] = $cut;
                }
            }

            return $map;
        }

        if ($offer->type !== OfferType::Amount) {
            return $map;
        }

        $pool = $offer->is_promo_code ? (int) $offer->value * $guests : (int) $offer->value;

        foreach ($quote->nightLines as $line) {
            if ($pool < 1 || ! isset($eligible[$line->night])) {
                continue;
            }

            $cut = min($pool, $remaining[$line->night] ?? 0);

            if ($cut < 1) {
                continue;
            }

            $map[$line->night] = $cut;
            $pool -= $cut;
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, int>>  $byOffer
     * @param  list<StayQuoteLine>  $before
     * @param  list<StayQuoteLine>  $after
     * @return array<string, int>
     */
    private function cappedNights(array $byOffer, array $before, array $after): array
    {
        if ($before !== [] && $after !== []) {
            $lastBefore = $before[array_key_last($before)];
            $lastAfter = $after[array_key_last($after)];
            $cut = (-$lastBefore->amount) - (-$lastAfter->amount);

            if ($cut > 0 && isset($byOffer[$lastAfter->code])) {
                $byOffer[$lastAfter->code] = $this->trimFromEnd($byOffer[$lastAfter->code], $cut);
            }
        }

        $merged = [];

        foreach ($byOffer as $nights) {
            foreach ($nights as $night => $amount) {
                $merged[$night] = ($merged[$night] ?? 0) + $amount;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, int>  $nights
     * @return array<string, int>
     */
    private function trimFromEnd(array $nights, int $overflow): array
    {
        $keys = array_keys($nights);

        for ($index = count($keys) - 1; $index >= 0 && $overflow > 0; $index--) {
            $night = $keys[$index];
            $take = min($nights[$night], $overflow);
            $nights[$night] -= $take;
            $overflow -= $take;
        }

        return $nights;
    }

    /**
     * @param  list<StayQuoteLine>  $lines
     */
    private function staySaving(array $lines): int
    {
        $saving = 0;

        foreach ($lines as $line) {
            $saving -= $line->amount;
        }

        return $saving;
    }

    /**
     * @param  list<StayQuoteLine>  $priced
     * @param  list<StayQuoteLine>  $zero
     * @return list<string>
     */
    private function pills(array $priced, array $zero): array
    {
        $codes = [];

        foreach ([...$priced, ...$zero] as $line) {
            if ($line->code !== 'online_deposit') {
                $codes[] = $line->code;
            }
        }

        if ($codes === []) {
            return [];
        }

        $pills = [];

        foreach (Offer::query()->whereIn('code', $codes)->get() as $offer) {
            if ($offer->enginePlacement() === 'badge' && is_string($offer->badge) && $offer->badge !== '') {
                $pills[] = $offer->badge;
            }
        }

        return $pills;
    }
}
