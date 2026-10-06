<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Operations\OccupancyCheck;
use Illuminate\Console\Command;

final class OccupancyCheckCommand extends Command
{
    protected $signature = 'iconic:occupancy-check';

    protected $description = 'Raise one low-occupancy alert per run of nights below the threshold';

    public function handle(OccupancyCheck $occupancy): int
    {
        $occupancy->run();
        $this->info('Occupancy checked.');

        return self::SUCCESS;
    }
}
