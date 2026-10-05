<?php

declare(strict_types=1);

namespace App\Support\Stays;

use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Property-local "today" and the operational check-in / check-out moments (09 H3, H18).
 */
final class StayClock
{
    public function __construct(private CurrentConfig $config) {}

    public function today(): CarbonImmutable
    {
        return BusinessTime::now()->startOfDay();
    }

    public function daysUntilArrival(StayDates $stay): int
    {
        return BusinessTime::calendarDaysBetween(
            $this->today()->toDateString(),
            $stay->checkIn()->toDateString(),
        );
    }

    public function daysSinceCheckOut(StayDates $stay): int
    {
        return BusinessTime::calendarDaysBetween(
            $stay->checkOut()->toDateString(),
            $this->today()->toDateString(),
        );
    }

    public function arrivalMoment(StayDates $stay): CarbonImmutable
    {
        return $this->moment($stay->checkIn()->toDateString(), $this->checkInTime());
    }

    /**
     * Survey and similar clocks: the recorded check-out, otherwise check-out at the published time (09 H10).
     */
    public function postStayAt(StayDates $stay, ?DateTimeInterface $checkedOutAt): CarbonImmutable
    {
        if ($checkedOutAt !== null) {
            return CarbonImmutable::instance($checkedOutAt);
        }

        return $this->checkOutMoment($stay);
    }

    public function checkOutMoment(StayDates $stay): CarbonImmutable
    {
        return $this->moment($stay->checkOut()->toDateString(), $this->checkOutTime());
    }

    public function isArrivalDayOrLater(StayDates $stay): bool
    {
        return $this->daysUntilArrival($stay) <= 0;
    }

    public function isNoShowWindow(StayDates $stay): bool
    {
        $today = $this->today()->toDateString();
        $checkIn = $stay->checkIn()->toDateString();

        if ($today > $checkIn) {
            return true;
        }

        if ($today < $checkIn) {
            return false;
        }

        return BusinessTime::now()->format('H:i') >= $this->noShowCutoff();
    }

    public function noShowCutoff(): string
    {
        return $this->config->businessRules()->stay->noShowCutoffTime;
    }

    public function checkOutTime(): string
    {
        return $this->config->businessRules()->stay->checkOutTime;
    }

    public function maxNights(): int
    {
        return $this->config->businessRules()->stay->maxNights;
    }

    private function checkInTime(): string
    {
        return $this->config->businessRules()->stay->checkInTime;
    }

    private function moment(string $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time));

        return BusinessTime::calendarDay($date)->setTime($hour, $minute)->utc();
    }
}
