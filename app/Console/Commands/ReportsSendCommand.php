<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Reports\ReportDispatch;
use Illuminate\Console\Command;

final class ReportsSendCommand extends Command
{
    protected $signature = 'iconic:reports-send';

    protected $description = 'Generate and email scheduled reports whose Galápagos moment has passed';

    public function handle(ReportDispatch $dispatch): int
    {
        $created = $dispatch->sendDue();
        $this->info('Scheduled report runs opened: '.$created.'.');

        return self::SUCCESS;
    }
}
