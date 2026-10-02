<?php

declare(strict_types=1);

namespace App\Support\Blocks;

use App\Support\Dates\Format;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class ScopeSummary
{
    /** @var list<string> */
    public const ALL_CABIN_CODES = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'OWNER'];

    /**
     * @param  list<array{property_code: string, date: string|DateTimeInterface, cabin_codes: list<string>}>  $scopes
     */
    public static function format(array $scopes): string
    {
        $groups = [];

        foreach ($scopes as $scope) {
            $date = self::dateString($scope['date']);
            $key = $date.'|'.$scope['property_code'];

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'property_code' => $scope['property_code'],
                    'date' => $date,
                    'cabin_codes' => [],
                ];
            }

            foreach ($scope['cabin_codes'] as $code) {
                $groups[$key]['cabin_codes'][] = $code;
            }
        }

        uksort($groups, function (string $left, string $right): int {
            [$leftDate, $leftProperty] = explode('|', $left, 2);
            [$rightDate, $rightProperty] = explode('|', $right, 2);

            return [$leftDate, $leftProperty] <=> [$rightDate, $rightProperty];
        });

        $parts = [];

        foreach ($groups as $group) {
            $parts[] = $group['property_code']
                .' · '
                .self::cabinsLabel($group['cabin_codes'])
                .' · '
                .Format::calendar(CarbonImmutable::createFromFormat('!Y-m-d', $group['date']));
        }

        return implode('; ', $parts);
    }

    /**
     * @param  list<string>  $codes
     */
    public static function cabinsLabel(array $codes): string
    {
        $unique = array_values(array_unique($codes));

        if (count($unique) === count(self::ALL_CABIN_CODES)
            && array_diff(self::ALL_CABIN_CODES, $unique) === []) {
            return 'Full property';
        }

        $suites = [];
        $owner = false;

        foreach ($unique as $code) {
            if ($code === 'OWNER') {
                $owner = true;

                continue;
            }

            if (preg_match('/^S(\d+)$/', $code, $matches) === 1) {
                $suites[] = (int) $matches[1];
            }
        }

        sort($suites);

        $parts = self::suiteRanges($suites);

        if ($owner) {
            $parts[] = "Owner's Suite";
        }

        return implode(', ', $parts);
    }

    /**
     * @param  list<int>  $numbers
     * @return list<string>
     */
    private static function suiteRanges(array $numbers): array
    {
        $ranges = [];
        $start = null;
        $end = null;

        foreach ($numbers as $number) {
            if ($start === null) {
                $start = $end = $number;

                continue;
            }

            if ($number === $end + 1) {
                $end = $number;

                continue;
            }

            $ranges[] = self::rangeLabel($start, $end);
            $start = $end = $number;
        }

        if ($start !== null) {
            $ranges[] = self::rangeLabel($start, $end);
        }

        return $ranges;
    }

    private static function rangeLabel(int $start, int $end): string
    {
        $from = 'Suite '.str_pad((string) $start, 2, '0', STR_PAD_LEFT);

        if ($start === $end) {
            return $from;
        }

        return $from.'–'.str_pad((string) $end, 2, '0', STR_PAD_LEFT);
    }

    private static function dateString(string|DateTimeInterface $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return substr($date, 0, 10);
    }
}
