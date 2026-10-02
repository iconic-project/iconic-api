<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Operations\OccupancyCheck;
use Illuminate\Console\Command;

final class OccupancyCheckCommand extends Command
{
    protected $signature = 'iconic:occupancy-check';

    protected $description = 'Raise low-occupancy alerts for open departures inside the configured window';

    public function handle(OccupancyCheck $occupancy): int
    {
        $occupancy->run();
        $this->info('Occupancy checked.');

        return self::SUCCESS;
    }
}
