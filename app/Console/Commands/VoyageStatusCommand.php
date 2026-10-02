<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Operations\VoyageStatus;
use Illuminate\Console\Command;

final class VoyageStatusCommand extends Command
{
    protected $signature = 'iconic:voyage-status';

    protected $description = 'Move FULLY_PAID voyages to ON_BOARD and ON_BOARD voyages to COMPLETED on Galápagos dates';

    public function handle(VoyageStatus $voyage): int
    {
        $voyage->run();
        $this->info('Voyage status checked.');

        return self::SUCCESS;
    }
}
