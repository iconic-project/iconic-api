<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AvailabilityChanged;
use App\Events\ConfigPublished;
use App\Services\Engine\EngineFeedVersion;

final class BumpEngineFeedVersion
{
    public function handle(AvailabilityChanged|ConfigPublished $event): void
    {
        EngineFeedVersion::bump();
    }
}
