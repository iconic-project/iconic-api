<?php

declare(strict_types=1);

namespace App\Support\Journeys;

use App\Models\Booking;
use App\Models\JourneyEnrolment;
use App\Models\JourneyStep;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class JourneyClock
{
    public function __construct(private readonly CurrentConfig $config) {}

    public function dueAt(JourneyEnrolment $enrolment, JourneyStep $step): CarbonImmutable
    {
        $delay = $step->delay;
        $rule = is_string($delay['rule'] ?? null) ? $delay['rule'] : null;

        if ($rule !== null) {
            return $this->fromRule($enrolment, $rule, (int) ($delay['slot'] ?? 0));
        }

        $anchor = is_string($delay['anchor'] ?? null) ? $delay['anchor'] : 'enrolment';
        $amount = (int) ($delay['amount'] ?? 0);
        $unit = is_string($delay['unit'] ?? null) ? $delay['unit'] : 'days';

        if ($anchor === 'reengagement') {
            return $this->reengagement($enrolment, $amount);
        }

        return $this->shift($this->anchor($enrolment, $anchor), $amount, $unit);
    }

    private function fromRule(JourneyEnrolment $enrolment, string $rule, int $slot): CarbonImmutable
    {
        $booking = $this->booking($enrolment);
        $rules = $this->config->businessRules();

        if ($rule === 'balance_reminder') {
            $days = $rules->payments->balanceReminderDays[$slot] ?? 0;

            return $this->balanceDue($booking)->subDays($days);
        }

        if ($rule === 'balance_due_plus_day') {
            return $this->balanceDue($booking)->addDays(1);
        }

        if ($rule === 'extras_due_hours') {
            return $this->checkInDay($booking)->subHours($rules->payments->extrasDueHours);
        }

        if ($rule === 'pretrip_days_before') {
            return $this->checkInDay($booking)->subDays($rules->documents->preArrivalDaysBefore);
        }

        return $this->checkInDay($booking);
    }

    private function reengagement(JourneyEnrolment $enrolment, int $months): CarbonImmutable
    {
        if ($enrolment->booking_id !== null) {
            return $this->shift($this->checkOutDay($this->booking($enrolment)), $months, 'months');
        }

        return $this->shift($this->instant($enrolment->enrolled_at), $months - 6, 'months');
    }

    private function anchor(JourneyEnrolment $enrolment, string $anchor): CarbonImmutable
    {
        if ($anchor === 'previous_step') {
            $sent = $enrolment->sends()->orderByDesc('id')->value('sent_at');

            if ($sent instanceof CarbonInterface) {
                return $this->instant($sent);
            }

            if (is_string($sent) && $sent !== '') {
                return CarbonImmutable::parse($sent)->utc();
            }

            return $this->instant($enrolment->enrolled_at);
        }

        if ($anchor === 'arrival' || $anchor === 'departure') {
            return $this->checkInDay($this->booking($enrolment));
        }

        if ($anchor === 'check_out' || $anchor === 'return') {
            return $this->checkOutDay($this->booking($enrolment));
        }

        if ($anchor === 'balance_due') {
            return $this->balanceDue($this->booking($enrolment));
        }

        return $this->instant($enrolment->enrolled_at);
    }

    private function shift(CarbonImmutable $anchor, int $amount, string $unit): CarbonImmutable
    {
        if ($unit === 'hours') {
            return $anchor->addHours($amount);
        }

        $local = BusinessTime::toBusiness($anchor)->startOfDay();
        $shifted = $unit === 'months' ? $local->addMonths($amount) : $local->addDays($amount);

        return $shifted->utc();
    }

    private function booking(JourneyEnrolment $enrolment): Booking
    {
        $enrolment->loadMissing('booking');
        $booking = $enrolment->booking;

        if (! $booking instanceof Booking) {
            throw new \RuntimeException('This journey step needs the enrolment booking.');
        }

        return $booking;
    }

    private function checkInDay(Booking $booking): CarbonImmutable
    {
        return BusinessTime::calendarDay($booking->stay()->checkIn()->toDateString());
    }

    private function checkOutDay(Booking $booking): CarbonImmutable
    {
        return BusinessTime::calendarDay($booking->stay()->checkOut()->toDateString());
    }

    private function balanceDue(Booking $booking): CarbonImmutable
    {
        return BusinessTime::calendarDay($booking->balanceDueDate()->toDateString());
    }

    private function instant(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->utc();
    }
}
