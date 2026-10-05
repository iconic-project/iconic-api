<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ClaimKind;
use App\Models\Booking;
use App\Models\InternalBlock;
use App\Models\RoomNightClaim;
use App\Services\Inventory\ClaimService;
use Illuminate\Console\Command;

final class ExpireHoldCommand extends Command
{
    protected $signature = 'inventory:expire-hold {reference}';

    protected $description = 'Force-expire the active hold of a booking or block (local/testing only)';

    public function handle(ClaimService $claims): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('inventory:expire-hold only runs in local and testing.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');
        $holder = Booking::query()
            ->where('reference', $reference)
            ->orWhere('request_reference', $reference)
            ->first();

        if ($holder === null) {
            $holder = InternalBlock::query()->where('reference', $reference)->first();
        }

        if ($holder === null) {
            $this->error('No booking or block with reference '.$reference.'.');

            return self::FAILURE;
        }

        $updated = RoomNightClaim::query()
            ->where('holder_type', $holder->getMorphClass())
            ->where('holder_id', $holder->getKey())
            ->where('kind', ClaimKind::Hold)
            ->whereNull('released_at')
            ->update(['expires_at' => now()->subMinute()]);

        if ($updated === 0) {
            $this->error('No active hold for '.$reference.'.');

            return self::FAILURE;
        }

        $released = $claims->releaseExpired();
        $this->info('Released '.$released.' expired hold(s) for '.$reference.'.');

        return self::SUCCESS;
    }
}
