<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\Cabin;
use App\Models\Departure;

final class CabinCodes
{
    public static function resolve(Departure $departure, string $input): ?Cabin
    {
        $departure->loadMissing('property.cabins');
        $needle = trim($input);

        if ($needle === '') {
            return null;
        }

        return $departure->property->cabins->first(
            fn (Cabin $cabin): bool => $cabin->code === $needle
                || strcasecmp($cabin->code, $needle) === 0
                || $cabin->label === $needle,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function normalizeRows(Departure $departure, array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $code = isset($row['cabin_code']) ? (string) $row['cabin_code'] : '';
            $cabin = self::resolve($departure, $code);

            if ($cabin instanceof Cabin) {
                $row['cabin_code'] = $cabin->code;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }
}
