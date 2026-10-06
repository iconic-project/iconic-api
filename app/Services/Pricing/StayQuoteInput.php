<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\BookingSegment;
use App\Support\Stays\StayDates;

/**
 * Guests already split into adults and children.
 *
 * TODO(OPEN: 09 H8) guests.infant_max_age is not on engine settings. Callers
 * must refuse ages below child_min_age until that rule exists. Every age in
 * childAges is priced as a child.
 */
final readonly class StayQuoteInput
{
    /**
     * @param  list<int>  $childAges
     */
    public function __construct(
        public StayDates $stay,
        public string $roomType,
        public int $adults,
        public array $childAges,
        public string $ratePlan,
        public ?string $promo = null,
        public ?int $ratesVersionId = null,
        public bool $onlineDeposit = false,
        public BookingSegment $channel = BookingSegment::D2C,
        public ?string $bookingDate = null,
    ) {}
}
