<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GuestExperience\SendSurveys;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use Illuminate\Console\Command;

final class NpsSurveyCommand extends Command
{
    protected $signature = 'iconic:nps-survey';

    protected $description = 'Send the post-trip survey once the configured hours after return have passed';

    public function handle(SendSurveys $send, CurrentConfig $config): int
    {
        $hours = $config->businessRules()->nps->surveyHoursAfterReturn;
        $sent = 0;

        Booking::query()
            ->where('status', BookingStatus::Completed)
            ->with(['departure.itinerary', 'guests', 'contact', 'group.coordinator'])
            ->orderBy('id')
            ->each(function (Booking $booking) use ($send, $hours, &$sent): void {
                $due = BusinessTime::calendarDay($booking->departure->returnDate()->toDateString())->addHours($hours);

                if (BusinessTime::now()->lt($due)) {
                    return;
                }

                $sent += $send->handle($booking);
            });

        $this->info('Post-trip survey deliveries recorded: '.$sent);

        return self::SUCCESS;
    }
}
