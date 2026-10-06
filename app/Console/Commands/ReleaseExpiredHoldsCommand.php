<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Inventory\ClaimService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ReleaseExpiredHoldsCommand extends Command
{
    protected $signature = 'inventory:release-expired-holds';

    protected $description = 'Release expired room holds';

    public function handle(ClaimService $claims): int
    {
        $count = $claims->releaseExpired();

        if ($count > 0) {
            Log::info('Released expired holds.', ['count' => $count]);
        }

        return self::SUCCESS;
    }
}
