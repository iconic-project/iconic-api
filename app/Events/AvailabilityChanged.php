<?php

declare(strict_types=1);

namespace App\Events;

use App\Support\Stays\StayDates;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class AvailabilityChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $propertyId,
        public StayDates $stay,
    ) {}
}
