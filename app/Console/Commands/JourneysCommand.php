<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Journeys\JourneyEngine;
use Illuminate\Console\Command;

final class JourneysCommand extends Command
{
    protected $signature = 'iconic:journeys';

    protected $description = 'Enrol, exit and advance CRM journeys that are due';

    public function handle(JourneyEngine $engine): int
    {
        $processed = $engine->run();
        $this->info('Journey enrolments advanced: '.$processed);

        return self::SUCCESS;
    }
}
