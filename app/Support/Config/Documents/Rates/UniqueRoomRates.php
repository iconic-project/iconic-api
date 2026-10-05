<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class UniqueRoomRates implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $seen = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = $row['room_type'] ?? null;
            $season = $row['season'] ?? null;

            if (! is_string($type) || $type === '' || ! is_string($season) || $season === '') {
                continue;
            }

            $key = $type.'|'.$season;

            if (isset($seen[$key])) {
                $fail('Room type '.$type.' already has a nightly rate for '.$season.'.');

                return;
            }

            $seen[$key] = true;
        }
    }
}
