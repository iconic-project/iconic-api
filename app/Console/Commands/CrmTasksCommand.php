<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Crm\TaskSweep;
use Illuminate\Console\Command;

final class CrmTasksCommand extends Command
{
    protected $signature = 'iconic:crm-tasks';

    protected $description = 'Raise CRM tasks the ledger calls for and auto-close the ones the RMS has cleared';

    public function handle(TaskSweep $tasks): int
    {
        $tasks->run();
        $this->info('CRM tasks synced.');

        return self::SUCCESS;
    }
}
