<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Operations\NightAudit;
use Illuminate\Console\Command;

final class VoyageStatusCommand extends Command
{
    protected $signature = 'iconic:voyage-status';

    protected $description = 'Deprecated alias for iconic:night-audit. Removed in Sprint 22.';

    public function handle(NightAudit $audit): int
    {
        $this->warn('iconic:voyage-status is deprecated. Use iconic:night-audit. This alias is removed in Sprint 22.');
        $audit->run();

        return self::SUCCESS;
    }
}
