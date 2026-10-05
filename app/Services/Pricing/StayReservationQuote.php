<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\RoomType;

final readonly class StayReservationQuote
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public RoomType $roomType,
        public StayQuote $quote,
        public array $warnings = [],
    ) {}
}
