<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GuestExperience\SendSurveys;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Stays\StayClock;
use Illuminate\Console\Command;

final class NpsSurveyCommand extends Command
{
    protected $signature = 'iconic:nps-survey';

    protected $description = 'Send the survey once the configured hours after check-out have passed';

    public function handle(SendSurveys $send, CurrentConfig $config, StayClock $clock): int
    {
        $hours = $config->businessRules()->nps->surveyHoursAfterCheckOut;
        $sent = 0;

        Booking::query()
            ->where('status', BookingStatus::CheckedOut)
            ->with(['guests', 'contact', 'group.coordinator'])
            ->orderBy('id')
            ->each(function (Booking $booking) use ($send, $hours, $clock, &$sent): void {
                $due = $clock->postStayAt($booking->stay(), $booking->checked_out_at)->addHours($hours);

                if (BusinessTime::now()->lt($due)) {
                    return;
                }

                $sent += $send->handle($booking);
            });

        $this->info('Post-trip survey deliveries recorded: '.$sent);

        return self::SUCCESS;
    }
}
