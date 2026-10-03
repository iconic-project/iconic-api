<?php

declare(strict_types=1);

namespace App\Support\Stays;

use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;

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

    public function checkOutMoment(StayDates $stay): CarbonImmutable
    {
        return $this->moment($stay->checkOut()->toDateString(), $this->checkOutTime());
    }

    public function isArrivalDayOrLater(StayDates $stay): bool
    {
        return $this->daysUntilArrival($stay) <= 0;
    }

    public function maxNights(): int
    {
        return $this->config->businessRules()->stay->maxNights;
    }

    private function checkInTime(): string
    {
        return $this->config->businessRules()->stay->checkInTime;
    }

    private function checkOutTime(): string
    {
        return $this->config->businessRules()->stay->checkOutTime;
    }

    private function moment(string $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time));

        return BusinessTime::calendarDay($date)->setTime($hour, $minute)->utc();
    }
}
