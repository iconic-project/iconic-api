<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Charter\CharterDepositClock;
use Illuminate\Console\Command;

final class CharterDepositsCommand extends Command
{
    protected $signature = 'iconic:charter-deposits';

    protected $description = 'Raise a task and a warning when a charter deposit is past its due date';

    public function handle(CharterDepositClock $clock): int
    {
        $raised = $clock->sweep();
        $this->info('Raised '.$raised.' charter deposit warning(s).');

        return self::SUCCESS;
    }
}
