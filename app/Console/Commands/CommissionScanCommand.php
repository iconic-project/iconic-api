<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Operations\CommissionScan;
use Illuminate\Console\Command;

final class CommissionScanCommand extends Command
{
    protected $signature = 'iconic:commission-scan';

    protected $description = 'Raise commission leakage findings and resolve the ones that have cleared';

    public function handle(CommissionScan $scan): int
    {
        $scan->run();
        $this->info('Commission scan finished.');

        return self::SUCCESS;
    }
}
