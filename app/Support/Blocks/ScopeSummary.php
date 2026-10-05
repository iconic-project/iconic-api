<?php

declare(strict_types=1);

namespace App\Support\Blocks;

use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * "Rooms 101, 102 · Tue 3 – Fri 6 Mar 2028 · 3 nights"
 * ends_on is exclusive, the same way check-out is.
 */
final class ScopeSummary
{
    /**
     * @param  list<string>  $labels
     */
    public static function format(array $labels, CarbonInterface $startsOn, CarbonInterface $endsOn): string
    {
        $start = self::date($startsOn);
        $end = self::date($endsOn);
        $nights = StayDates::of($start, $end)->nights();
        $noun = count($labels) === 1 ? 'Room' : 'Rooms';
        $nightWord = $nights === 1 ? 'night' : 'nights';

        return $noun.' '.implode(', ', $labels)
            .' · '.self::range($start, $end)
            .' · '.$nights.' '.$nightWord;
    }

    private static function range(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $startText = $start->format('D j');

        if ($start->year !== $end->year) {
            $startText = $start->format('D j M Y');
        } elseif ($start->month !== $end->month) {
            $startText = $start->format('D j M');
        }

        return $startText.' – '.$end->format('D j M Y');
    }

    private static function date(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'))
            ?? CarbonImmutable::parse($date->format('Y-m-d'));
    }
}
