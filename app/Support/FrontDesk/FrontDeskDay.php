<?php

declare(strict_types=1);

namespace App\Support\FrontDesk;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Collection;

final class FrontDeskDay
{
    /**
     * @param  Collection<int, Booking>  $arrivals
     * @param  Collection<int, Booking>  $inHouse
     * @param  Collection<int, Booking>  $departures
     */
    public function __construct(
        public string $date,
        public Collection $arrivals,
        public Collection $inHouse,
        public Collection $departures,
    ) {}
}
