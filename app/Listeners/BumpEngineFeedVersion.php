<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AvailabilityChanged;
use App\Events\ConfigPublished;
use App\Services\Engine\EngineFeedVersion;
use App\Support\Inventory\DepartureNightClaims;
use Illuminate\Support\Facades\Cache;

final class BumpEngineFeedVersion
{
    public function handle(AvailabilityChanged|ConfigPublished $event): void
    {
        EngineFeedVersion::bump();

        if ($event instanceof AvailabilityChanged) {
            foreach (DepartureNightClaims::departureIds($event->propertyId, $event->stay) as $departureId) {
                Cache::forget(EngineFeedVersion::cabinsKey($departureId));
            }
        }
    }
}
