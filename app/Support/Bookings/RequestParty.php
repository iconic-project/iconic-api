<?php

declare(strict_types=1);

namespace App\Support\Bookings;

final class RequestParty
{
    public static function label(int $adults, int $children): string
    {
        $party = $adults === 1 ? '1 adult' : $adults.' adults';

        if ($children === 1) {
            $party .= ' + 1 child';
        } elseif ($children > 1) {
            $party .= ' + '.$children.' children';
        }

        return $party.' · 1 room';
    }
}
