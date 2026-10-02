<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Operations\LedgerCheck;
use Illuminate\Console\Command;

final class LedgerCheckCommand extends Command
{
    protected $signature = 'iconic:ledger-check';

    protected $description = 'Compare settled card payments and refunds with Stripe events and report drift';

    public function handle(LedgerCheck $ledger): int
    {
        $ledger->run();
        $this->info('Ledger checked.');

        return self::SUCCESS;
    }
}
