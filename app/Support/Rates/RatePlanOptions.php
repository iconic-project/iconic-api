<?php

declare(strict_types=1);

namespace App\Support\Rates;

use App\Models\RoomType;
use App\Support\Config\Documents\Rates\RatePlan;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Stays\StayDates;

/**
 * Plans offered for a room type and stay. v1 returns every published plan.
 */
final class RatePlanOptions
{
    /**
     * @return list<RatePlan>
     */
    public function forStay(RatesDocument $rates, RoomType $roomType, StayDates $stay): array
    {
        return $rates->ratePlans;
    }
}
