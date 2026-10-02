<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Support\BusinessTime;
use Illuminate\Console\Command;

final class SetOverdueFixtureCommand extends Command
{
    protected $signature = 'iconic:set-overdue-fixture {reference=ANK-2026-0018}';

    protected $description = 'Set balance_due_date_override into the past (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('iconic:set-overdue-fixture only runs in local and testing.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');
        $booking = Booking::query()->where('reference', $reference)->first();

        if ($booking === null) {
            $this->error('No booking with reference '.$reference.'.');

            return self::FAILURE;
        }

        $yesterday = BusinessTime::now()->subDay();
        $booking->balance_due_date_override = $yesterday;
        $booking->save();

        $this->info('Set '.$reference.' balance_due_date_override to '.$yesterday->toDateString().'.');

        return self::SUCCESS;
    }
}
