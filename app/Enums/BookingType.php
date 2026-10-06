<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Pricing\QuoteType;

enum BookingType: string
{
    case Room = 'ROOM';
    case Charter = 'CHARTER';

    public function quoteType(): QuoteType
    {
        return match ($this) {
            self::Room => QuoteType::Room,
            self::Charter => QuoteType::Charter,
        };
    }
}
