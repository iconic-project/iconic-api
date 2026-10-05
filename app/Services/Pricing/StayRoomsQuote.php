<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Support\Stays\StayDates;

final readonly class StayRoomsQuote
{
    /**
     * @param  list<array{room_type: string, result: StayReservationQuote|NoRate|GuestsInvalid}>  $rooms
     */
    public function __construct(
        public StayDates $stay,
        public array $rooms,
        public ?int $total,
        public ?int $deposit,
        public ?int $totalIncludingChargedTaxes,
    ) {}
}
