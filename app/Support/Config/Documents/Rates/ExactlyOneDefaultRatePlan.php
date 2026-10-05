<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An empty list is valid so a database with no hotel rates yet still passes
 * config-verify. A non-empty list needs exactly one default.
 */
final class ExactlyOneDefaultRatePlan implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        $defaults = 0;

        foreach ($value as $plan) {
            if (! is_array($plan)) {
                continue;
            }

            if (self::isDefault($plan['default'] ?? false)) {
                $defaults++;
            }
        }

        if ($defaults !== 1) {
            $fail('Exactly one rate plan must be the default.');
        }
    }

    private static function isDefault(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
