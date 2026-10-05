<?php

declare(strict_types=1);

namespace App\Support\Blocks;

use App\Enums\ClaimKind;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * "Room 204 is sold on Wed 4 Mar 2028 (ANK-2028-0012)"
 */
final class ConflictMessage
{
    public static function line(
        string $roomLabel,
        DateTimeInterface $night,
        ClaimKind $kind,
        ?string $reference,
    ): string {
        $verb = match ($kind) {
            ClaimKind::Hold => 'held',
            ClaimKind::Block => 'blocked',
            ClaimKind::Booking => 'sold',
        };

        $when = CarbonImmutable::createFromFormat('!Y-m-d', $night->format('Y-m-d'));
        $day = $when instanceof CarbonImmutable ? $when->format('D j M Y') : $night->format('D j M Y');
        $sentence = $roomLabel.' is '.$verb.' on '.$day;

        if (is_string($reference) && $reference !== '') {
            $sentence .= ' ('.$reference.')';
        }

        return $sentence;
    }

    /**
     * @param  list<string>  $lines
     */
    public static function join(array $lines): string
    {
        return implode(' ', $lines);
    }
}
