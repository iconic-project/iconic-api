<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Support\Config\Documents\CancellationBand;
use App\Support\Rounding;
use InvalidArgumentException;

final class CancellationPenalty
{
    /**
     * @param  list<array{min_days: int, penalty_pct: int}|CancellationBand>  $bands
     * @return array{min_days: int, penalty_pct: int}
     */
    public static function bandFor(int $daysBeforeArrival, array $bands): array
    {
        $normalized = self::normalize($bands);

        foreach ($normalized as $band) {
            if ($daysBeforeArrival >= $band['min_days']) {
                return $band;
            }
        }

        return $normalized[array_key_last($normalized)];
    }

    /**
     * @param  array{min_days: int, penalty_pct: int}|CancellationBand  $band
     * @param  list<array{min_days: int, penalty_pct: int}|CancellationBand>  $bands
     */
    public static function label(array|CancellationBand $band, array $bands): string
    {
        $current = self::one($band);
        $normalized = self::normalize($bands);
        $index = self::indexOf($current['min_days'], $normalized);

        if ($index === null) {
            $normalized = self::normalize([...$normalized, $current]);
            $index = self::indexOf($current['min_days'], $normalized);
        }

        if ($index === null) {
            throw new InvalidArgumentException('Band is not in the configured list.');
        }

        if ($index === 0) {
            return '≥'.$current['min_days'].' days';
        }

        return $current['min_days'].'–'.($normalized[$index - 1]['min_days'] - 1).' days';
    }

    public static function penalty(int $total, int $pct): int
    {
        return Rounding::halfUp($total * $pct / 100);
    }

    public static function refundDue(int $paid, int $penalty): int
    {
        return max(0, $paid - $penalty);
    }

    /**
     * @param  list<array{min_days: int, penalty_pct: int}|CancellationBand>  $bands
     * @return list<array{min_days: int, penalty_pct: int}>
     */
    private static function normalize(array $bands): array
    {
        $normalized = array_map(self::one(...), $bands);

        if ($normalized === []) {
            throw new InvalidArgumentException('Cancellation bands must not be empty.');
        }

        usort(
            $normalized,
            fn (array $a, array $b): int => $b['min_days'] <=> $a['min_days'],
        );

        return $normalized;
    }

    /**
     * @param  list<array{min_days: int, penalty_pct: int}>  $bands
     */
    private static function indexOf(int $minDays, array $bands): ?int
    {
        foreach ($bands as $i => $candidate) {
            if ($candidate['min_days'] === $minDays) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array{min_days?: int, penalty_pct?: int}|CancellationBand  $band
     * @return array{min_days: int, penalty_pct: int}
     */
    private static function one(array|CancellationBand $band): array
    {
        if ($band instanceof CancellationBand) {
            return $band->toArray();
        }

        return [
            'min_days' => (int) ($band['min_days'] ?? 0),
            'penalty_pct' => (int) ($band['penalty_pct'] ?? 0),
        ];
    }
}
