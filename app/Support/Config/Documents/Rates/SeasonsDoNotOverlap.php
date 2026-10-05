<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SeasonsDoNotOverlap implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $ranges = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = $row['code'] ?? null;
            $from = $row['from'] ?? null;
            $to = $row['to'] ?? null;

            if (! is_string($code) || ! is_string($from) || ! is_string($to) || $from > $to) {
                continue;
            }

            $ranges[] = ['code' => $code, 'from' => $from, 'to' => $to];
        }

        usort(
            $ranges,
            fn (array $left, array $right): int => $left['from'] <=> $right['from'] ?: $left['code'] <=> $right['code'],
        );

        $count = count($ranges);

        for ($left = 0; $left < $count; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                if ($ranges[$right]['from'] > $ranges[$left]['to']) {
                    break;
                }

                $fail('Seasons '.$ranges[$left]['code'].' and '.$ranges[$right]['code'].' overlap.');
            }
        }
    }
}
