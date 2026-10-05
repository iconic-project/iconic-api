<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\RoomType;
use App\Support\Config\Documents\Rates\RatePlan;
use App\Support\Config\Documents\Rates\Season;
use App\Support\Config\Documents\Rates\Supplement;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Rounding;

/**
 * Pure nightly quote. Guest-count limits are the caller's job.
 * Deposit and balance timing come from the rate plan.
 */
final class RoomPricer
{
    public function quote(RatesDocument $rates, RoomType $roomType, StayQuoteInput $input): StayQuote|NoRate
    {
        $plan = $this->plan($rates, $input->ratePlan);

        if (! $plan instanceof RatePlan) {
            return new NoRate('No rate plan '.$input->ratePlan.'.');
        }

        $nights = [];
        $seasonNames = [];
        $supplementLabels = [];

        foreach ($input->stay->eachNight() as $night) {
            $date = $night->toDateString();
            $season = $this->season($rates, $date);

            if (! $season instanceof Season) {
                return new NoRate('No rate for '.$input->roomType.' on '.$date);
            }

            $base = $this->nightly($rates, $input->roomType, $season->code);

            if ($base === null) {
                return new NoRate('No rate for '.$input->roomType.' on '.$date);
            }

            $priced = $this->priceNight($rates, $roomType, $input, $plan, $season, $date, $base);
            $nights[] = $priced;

            if (! in_array($season->name, $seasonNames, true)) {
                $seasonNames[] = $season->name;
            }

            foreach ($priced['supplement_labels'] as $label) {
                if (! in_array($label, $supplementLabels, true)) {
                    $supplementLabels[] = $label;
                }
            }
        }

        $nightCount = count($nights);
        $baseSum = $this->sum($nights, 'base');
        $extraAdult = $this->sum($nights, 'extraAdult');
        $extraChild = $this->sum($nights, 'extraChild');
        $single = $this->sum($nights, 'single');
        $dow = $this->sum($nights, 'dow');
        $supplements = $this->sum($nights, 'supplements');
        $planAdjust = $this->sum($nights, 'planAdjust');
        $nightTotal = $this->sum($nights, 'total');

        $lines = [
            new StayQuoteLine('room', 'room', [
                'name' => (string) $roomType->name,
                'nights' => $nightCount,
                'seasons' => implode(', ', $seasonNames),
                'season_count' => count($seasonNames),
            ], $baseSum),
        ];

        $this->push($lines, 'extra_adult', 'extra_adult', [], $extraAdult);
        $this->push($lines, 'extra_child', 'extra_child', [], $extraChild);
        $this->push($lines, 'single_occupancy', 'single_occupancy', [], $single);
        $this->push($lines, 'day_of_week', 'day_of_week', [], $dow);
        $this->push($lines, 'supplement', 'supplement', [
            'label' => implode(', ', $supplementLabels),
        ], $supplements);
        $this->push($lines, 'rate_plan', 'rate_plan', [
            'name' => $plan->name,
        ], $planAdjust);

        $los = $this->lengthOfStayDiscount($rates, $nightCount, $nightTotal);

        if ($los !== 0) {
            $lines[] = new StayQuoteLine('length_of_stay', 'length_of_stay', [], -$los);
        }

        $total = $nightTotal - $los;
        $depositPct = $plan->depositPct;
        $terms = QuoteTerms::forPlan($plan);

        return new StayQuote(
            array_map(fn (array $night): NightLine => $night['line'], $nights),
            $lines,
            $total,
            $depositPct,
            Rounding::halfUp($total * $depositPct / 100),
            $input->ratesVersionId,
            $terms,
        );
    }

    /**
     * @param  list<array{line: NightLine, extraAdult: int, extraChild: int, supplement_labels: list<string>}>  $nights
     */
    private function sum(array $nights, string $field): int
    {
        $total = 0;

        foreach ($nights as $night) {
            $total += match ($field) {
                'base' => $night['line']->base,
                'extraAdult' => $night['extraAdult'],
                'extraChild' => $night['extraChild'],
                'single' => $night['line']->single,
                'dow' => $night['line']->dow,
                'supplements' => $night['line']->supplements,
                'planAdjust' => $night['line']->planAdjust,
                'total' => $night['line']->total,
                default => 0,
            };
        }

        return $total;
    }

    /**
     * @param  list<StayQuoteLine>  $lines
     * @param  array<string, int|string>  $params
     */
    private function push(array &$lines, string $code, string $key, array $params, int $amount): void
    {
        if ($amount === 0) {
            return;
        }

        $lines[] = new StayQuoteLine($code, $key, $params, $amount);
    }

    /**
     * @return array{line: NightLine, extraAdult: int, extraChild: int, supplement_labels: list<string>}
     */
    private function priceNight(
        RatesDocument $rates,
        RoomType $roomType,
        StayQuoteInput $input,
        RatePlan $plan,
        Season $season,
        string $date,
        int $base,
    ): array {
        $children = count($input->childAges);
        $baseSlots = max(0, $roomType->base_occupancy);
        $adultsInBase = min(max(0, $input->adults), $baseSlots);
        $childrenInBase = min($children, $baseSlots - $adultsInBase);
        $extraAdult = max(0, $input->adults) - $adultsInBase;
        $extraChild = $children - $childrenInBase;
        $extraAdultAmount = $extraAdult * $rates->occupancy->extraAdultNightly;
        $extraChildAmount = $extraChild * $rates->occupancy->extraChildNightly;
        $running = $base + $extraAdultAmount + $extraChildAmount;

        $single = 0;

        if ($input->adults + $children === 1) {
            $single = Rounding::halfUp($base * $rates->occupancy->singleOccupancyPct / 100);
            $running += $single;
        }

        $dowPct = $rates->dayOfWeek->onIsoWeekday($this->isoWeekday($date));
        $afterDow = Rounding::halfUp($running * (100 + $dowPct) / 100);
        $dow = $afterDow - $running;
        $running = $afterDow;

        $supplementAmount = 0;
        $supplementLabels = [];
        $guests = max(0, $input->adults) + $children;

        foreach ($rates->supplements as $supplement) {
            if (! $this->covers($supplement, $date)) {
                continue;
            }

            $amount = $supplement->basis === 'PERSON'
                ? $supplement->perNight * $guests
                : $supplement->perNight;
            $supplementAmount += $amount;

            if ($amount !== 0) {
                $supplementLabels[] = $supplement->label;
            }
        }

        $running += $supplementAmount;
        $afterPlan = Rounding::halfUp($running * (100 + $plan->adjustPct) / 100);
        $planAdjust = $afterPlan - $running;

        return [
            'line' => new NightLine(
                $date,
                $season->code,
                $base,
                $extraAdultAmount + $extraChildAmount,
                $single,
                $dow,
                $supplementAmount,
                $planAdjust,
                $afterPlan,
            ),
            'extraAdult' => $extraAdultAmount,
            'extraChild' => $extraChildAmount,
            'supplement_labels' => $supplementLabels,
        ];
    }

    private function lengthOfStayDiscount(RatesDocument $rates, int $nights, int $nightTotal): int
    {
        $pct = 0;
        $minNights = -1;

        foreach ($rates->lengthOfStay as $band) {
            if ($band->minNights <= $nights && $band->minNights > $minNights) {
                $minNights = $band->minNights;
                $pct = $band->discountPct;
            }
        }

        if ($pct === 0) {
            return 0;
        }

        return Rounding::halfUp($nightTotal * $pct / 100);
    }

    private function season(RatesDocument $rates, string $date): ?Season
    {
        foreach ($rates->seasons as $season) {
            if ($season->contains($date)) {
                return $season;
            }
        }

        return null;
    }

    private function nightly(RatesDocument $rates, string $roomType, string $season): ?int
    {
        foreach ($rates->roomRates as $rate) {
            if ($rate->roomType === $roomType && $rate->season === $season) {
                return $rate->nightly;
            }
        }

        return null;
    }

    private function plan(RatesDocument $rates, string $code): ?RatePlan
    {
        foreach ($rates->ratePlans as $plan) {
            if ($plan->code === $code) {
                return $plan;
            }
        }

        return null;
    }

    private function covers(Supplement $supplement, string $date): bool
    {
        return $date >= $supplement->from && $date <= $supplement->to;
    }

    private function isoWeekday(string $date): int
    {
        $stamp = strtotime($date.' UTC');

        if ($stamp === false) {
            return 0;
        }

        $weekday = (int) gmdate('N', $stamp);

        return $weekday >= 1 && $weekday <= 7 ? $weekday : 0;
    }
}
