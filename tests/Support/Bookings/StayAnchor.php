<?php

declare(strict_types=1);

namespace Tests\Support\Bookings;

use App\Models\Property;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;

/**
 * A property and a check-in date for tests that used to build a departure.
 */
final class StayAnchor
{
    public function __construct(
        public string $date,
        public Property $property,
        public bool $festive = false,
        public int $property_id = 0,
    ) {
        $this->property_id = $property->id;
    }

    public function stayDates(): StayDates
    {
        $checkOut = CarbonImmutable::parse($this->date)->addDays(7)->toDateString();

        return StayDates::of($this->date, $checkOut);
    }
}
