<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class DayOfWeekKeys implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach (array_keys($value) as $key) {
            if (! in_array((string) $key, ['1', '2', '3', '4', '5', '6', '7'], true)) {
                $fail('Day of week accepts ISO weekdays 1 to 7 only.');

                return;
            }
        }
    }
}
