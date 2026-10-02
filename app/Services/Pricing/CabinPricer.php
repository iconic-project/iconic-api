<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\CabinCategory;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Money;
use App\Support\Rounding;

/**
 * Pure cabin/charter quote from a RatesDocument.
 *
 * Guest-count limits (max per cabin) and child-age rules are the caller's
 * job, using engine settings. This class only prices the guests it is given.
 */
final class CabinPricer
{
    public function quote(RatesDocument $rates, QuoteInput $input): Quote|NoRate
    {
        $year = $rates->year($input->year);

        if ($year === null) {
            return new NoRate($this->missingRateReason($input));
        }

        if ($input->type === QuoteType::Charter) {
            return $this->quoteCharter($rates, $year->charterWeek, $input);
        }

        return $this->quoteCabin($rates, $year->price($this->cabinCategory($input)->value), $input);
    }

    private function quoteCharter(RatesDocument $rates, int $base, QuoteInput $input): Quote
    {
        $lines = [
            new QuoteLine(
                'base',
                'Charter — full property, 7 nights ('.$input->year.' rate)',
                $base,
            ),
        ];
        $total = $base;

        if ($input->festive) {
            $supplement = $rates->rules->festiveSupplementCharter;
            $lines[] = new QuoteLine('festive_supplement', 'Festive supplement — charter', $supplement);
            $total += $supplement;
        }

        $depositPct = $rates->terms->charterDepositPct;

        return new Quote(
            $lines,
            $total,
            $depositPct,
            Rounding::halfUp($total * $depositPct / 100),
        );
    }

    private function quoteCabin(RatesDocument $rates, int $base, QuoteInput $input): Quote
    {
        $rules = $rates->rules;
        $adults = $input->adults;
        $children = $input->children;
        $guests = $adults + $children;
        $festive = $input->festive;

        $lines = [
            new QuoteLine('base', $this->baseLabel($adults, $children, $base, $input->year), $base * $guests),
        ];
        $total = $base * $guests;
        $childCount = 0;

        if (! $festive && $children > 0) {
            $childCount = min(
                $children,
                $adults * $rules->childDiscountsPerAdult,
                $rules->childDiscountsPerCabin,
            );

            if ($childCount > 0) {
                $discount = Rounding::halfUp($base * $rules->childDiscountPct / 100) * $childCount;
                $lines[] = new QuoteLine(
                    'child_discount',
                    'Child discount −'.$rules->childDiscountPct.'% ppdo × '.$childCount,
                    -$discount,
                );
                $total -= $discount;
            }
        }

        if ($guests === 1) {
            $supplement = Rounding::halfUp($base * $rules->singleSupplementPct / 100);
            $lines[] = new QuoteLine(
                'single_supplement',
                'Single supplement +'.$rules->singleSupplementPct.'% ppdo',
                $supplement,
            );
            $total += $supplement;
        }

        if ($guests === 3 && ! $festive && $childCount === 0) {
            $discount = Rounding::halfUp($base * $rules->tripleDiscountPct / 100) * 3;
            $lines[] = new QuoteLine(
                'triple_discount',
                'Triple sharing −'.$rules->tripleDiscountPct.'% ppdo × 3',
                -$discount,
            );
            $total -= $discount;
        }

        if ($input->backToBack && ! $festive) {
            $discount = Rounding::halfUp($total * $rules->backToBackPct / 100);
            $lines[] = new QuoteLine(
                'back_to_back',
                'Back-to-back −'.$rules->backToBackPct.'%',
                -$discount,
            );
            $total -= $discount;
        }

        if ($festive) {
            $supplement = $rules->festiveSupplementPp * $guests;
            $lines[] = new QuoteLine(
                'festive_supplement',
                'Festive supplement +'.Money::format($rules->festiveSupplementPp).' × '.$guests,
                $supplement,
            );
            $total += $supplement;
        }

        $depositPct = $rates->terms->cabinDepositPct;

        return new Quote(
            $lines,
            $total,
            $depositPct,
            Rounding::halfUp($total * $depositPct / 100),
        );
    }

    private function cabinCategory(QuoteInput $input): CabinCategory
    {
        return $input->category ?? CabinCategory::Suite;
    }

    private function missingRateReason(QuoteInput $input): string
    {
        if ($input->type === QuoteType::Charter) {
            return 'No '.$input->year.' Charter rate';
        }

        $label = $this->cabinCategory($input) === CabinCategory::Owner
            ? "Owner's Suite"
            : 'Suite';

        return 'No '.$input->year.' '.$label.' rate';
    }

    private function baseLabel(int $adults, int $children, int $base, int $year): string
    {
        $label = $adults.' adult'.($adults !== 1 ? 's' : '');

        if ($children > 0) {
            $label .= ' + '.$children.' child'.($children > 1 ? 'ren' : '');
        }

        return $label.' @ '.Money::format($base).' ppdo ('.$year.')';
    }
}
