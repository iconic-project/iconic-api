<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Support\BusinessTime;
use Illuminate\Console\Command;

final class SetReminderFixtureCommand extends Command
{
    protected $signature = 'iconic:set-reminder-fixture {reference=ANK-2026-0003}';

    protected $description = 'Set balance_due_date_override to today + 21 days (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('iconic:set-reminder-fixture only runs in local and testing.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');
        $booking = Booking::query()->where('reference', $reference)->first();

        if ($booking === null) {
            $this->error('No booking with reference '.$reference.'.');

            return self::FAILURE;
        }

        $due = BusinessTime::now()->addDays(21);
        $booking->balance_due_date_override = $due;
        $booking->save();

        $this->info('Set '.$reference.' balance_due_date_override to '.$due->toDateString().'.');

        return self::SUCCESS;
    }
}
