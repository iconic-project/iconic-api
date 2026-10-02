<?php

declare(strict_types=1);

namespace App\Support\Blocks;

use App\Enums\ClaimKind;
use App\Models\Departure;
use App\Support\Dates\Format;

final class ConflictMessage
{
    public static function line(Departure $departure, string $cabinLabel, ClaimKind $kind): string
    {
        $verb = match ($kind) {
            ClaimKind::Hold => 'held',
            ClaimKind::Block => 'blocked',
            ClaimKind::Booking => 'sold',
        };

        $departure->loadMissing('property');

        return $cabinLabel
            .' on '
            .Format::calendar($departure->date)
            .' · '
            .$departure->property->code
            .' is '
            .$verb
            .'.';
    }

    /**
     * @param  list<string>  $lines
     */
    public static function join(array $lines): string
    {
        return implode(' ', $lines);
    }
}
