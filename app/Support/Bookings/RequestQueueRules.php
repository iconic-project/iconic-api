<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Services\Config\CurrentConfig;
use App\Support\Config\Documents\Rates\RatePlan;

final class RequestQueueRules
{
    public static function businessDayMinutes(CurrentConfig $config): int
    {
        $holds = $config->businessRules()->holds;
        [$startHour, $startMinute] = array_map(intval(...), explode(':', $holds->businessDayStart));
        [$endHour, $endMinute] = array_map(intval(...), explode(':', $holds->businessDayEnd));

        return ($endHour * 60 + $endMinute) - ($startHour * 60 + $startMinute);
    }

    /**
     * @return array{
     *     near_term_business_hours: int,
     *     long_lead_business_days: int,
     *     near_term_max_days: int,
     *     response_hours: int,
     *     business_day_minutes: int,
     *     deposit_pct: int
     * }
     */
    public static function fromConfig(CurrentConfig $config): array
    {
        $holds = $config->businessRules()->holds;

        return [
            'near_term_business_hours' => $holds->nearTermBusinessHours,
            'long_lead_business_days' => $holds->longLeadBusinessDays,
            'near_term_max_days' => $holds->nearTermMaxDays,
            'response_hours' => $config->businessRules()->sla->responseHours,
            'business_day_minutes' => self::businessDayMinutes($config),
            'deposit_pct' => self::defaultPlan($config)->depositPct,
        ];
    }

    private static function defaultPlan(CurrentConfig $config): RatePlan
    {
        $plans = $config->rates()->ratePlans;

        foreach ($plans as $plan) {
            if ($plan->isDefault) {
                return $plan;
            }
        }

        $first = $plans[0] ?? null;

        if (! $first instanceof RatePlan) {
            throw new \RuntimeException('No rate plan is published.');
        }

        return $first;
    }
}
