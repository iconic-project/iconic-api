<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Operations\NightAudit;
use Illuminate\Console\Command;

final class NightAuditCommand extends Command
{
    protected $signature = 'iconic:night-audit';

    protected $description = 'Raise front-desk alerts for arrivals, late departures, and guests still in house. Changes no booking status.';

    public function handle(NightAudit $audit): int
    {
        $audit->run();
        $this->info('Night audit checked.');

        return self::SUCCESS;
    }
}
