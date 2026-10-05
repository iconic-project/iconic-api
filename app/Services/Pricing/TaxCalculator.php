<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\TaxBasis;
use App\Support\Config\Documents\Tax;
use App\Support\Rounding;

/**
 * Pure stay taxes. Charged lines are the only ones added to the amount due.
 */
final class TaxCalculator
{
    /**
     * @param  list<Tax>  $taxes
     * @return list<TaxLine>
     */
    public function forStay(StayQuote $quote, StayParty $guests, array $taxes): array
    {
        $nights = count($quote->nightLines);
        $lines = [];

        foreach ($taxes as $tax) {
            $lines[] = $this->line($quote, $guests, $tax, $nights);
        }

        return $lines;
    }

    private function line(StayQuote $quote, StayParty $guests, Tax $tax, int $nights): TaxLine
    {
        $children = $this->chargeableChildren($guests, $tax);

        [$key, $params, $amount] = match ($tax->basis) {
            TaxBasis::PerStay => ['tax_per_stay', ['label' => $tax->label], $tax->amount],
            TaxBasis::PerNight => [
                'tax_per_night',
                ['label' => $tax->label, 'nights' => $nights],
                $tax->amount * $nights,
            ],
            TaxBasis::PerPersonPerNight => $this->perPerson($tax, $guests->adults, $children, $nights),
            TaxBasis::PctOfRoom => [
                'tax_pct_of_room',
                ['label' => $tax->label, 'amount' => $tax->amount],
                Rounding::halfUp($quote->total * $tax->amount / 100),
            ],
        };

        return new TaxLine($tax->code, $key, $params, $amount, $tax->charged, $tax->shownInPricePanel);
    }

    /**
     * @return array{0: string, 1: array<string, int|string>, 2: int}
     */
    private function perPerson(Tax $tax, int $adults, int $children, int $nights): array
    {
        $people = $adults + $children;
        $params = [
            'label' => $tax->label,
            'adults' => $adults,
            'nights' => $nights,
        ];
        $key = 'tax_per_person_per_night';

        if ($children > 0) {
            $key = 'tax_per_person_per_night_children';
            $params['children'] = $children;
        }

        return [$key, $params, $tax->amount * $people * $nights];
    }

    private function chargeableChildren(StayParty $guests, Tax $tax): int
    {
        if ($tax->basis !== TaxBasis::PerPersonPerNight) {
            return 0;
        }

        $count = 0;

        foreach ($guests->childAges as $age) {
            if ($tax->childExemptUnderAge !== null && $age < $tax->childExemptUnderAge) {
                continue;
            }

            $count++;
        }

        return $count;
    }
}
