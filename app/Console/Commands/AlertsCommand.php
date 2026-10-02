<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Alerts\AlertMailer;
use App\Support\Alerts\AlertSweep;
use Illuminate\Console\Command;

final class AlertsCommand extends Command
{
    protected $signature = 'iconic:alerts';

    protected $description = 'Raise alerts the ledger calls for, resolve the ones whose facts have cleared, and email critical alerts once';

    public function handle(AlertSweep $alerts, AlertMailer $mail): int
    {
        $alerts->run();
        $mail->sendOutstanding();
        $this->info('Alerts synced.');

        return self::SUCCESS;
    }
}
