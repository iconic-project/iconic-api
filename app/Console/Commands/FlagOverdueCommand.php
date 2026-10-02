<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\BookingOverdueFlagged;
use App\Models\Booking;
use App\Support\History\History;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class FlagOverdueCommand extends Command
{
    protected $signature = 'iconic:flag-overdue';

    protected $description = 'Write booking.overdue_flagged once per overdue episode (does not change status)';

    public function handle(): int
    {
        $flagged = 0;

        Booking::query()
            ->overdue()
            ->with('history')
            ->orderBy('bookings.id')
            ->each(function (Booking $booking) use (&$flagged): void {
                $cutoff = $booking->dueDateChangedAt();
                $already = $booking->history
                    ->where('event', 'booking.overdue_flagged')
                    ->contains(fn ($entry): bool => $entry->created_at->gte($cutoff));

                if ($already) {
                    return;
                }

                DB::transaction(function () use ($booking): void {
                    History::record($booking, 'booking.overdue_flagged', after: [
                        'overdue_since' => $booking->overdueSince()?->toDateString(),
                        'overdue_days' => $booking->overdueDays(),
                        'balance' => $booking->cruiseOutstanding(),
                    ], system: true);

                    BookingOverdueFlagged::dispatch($booking);
                });

                $flagged++;
            });

        $this->info('Flagged '.$flagged.' overdue booking(s).');

        return self::SUCCESS;
    }
}
