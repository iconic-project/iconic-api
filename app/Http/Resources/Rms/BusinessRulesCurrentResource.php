<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Services\Config\CurrentConfig;
use App\Support\BusinessRules\Registry;
use Illuminate\Http\Request;

class BusinessRulesCurrentResource extends ConfigCurrentResource
{
    /**
     * @return array{
     *     version: int,
     *     document: array{
     *         commission: array{cap_pct: int, default_pct: int, payable_days_after_check_out: int},
     *         modification_fee_usd: int,
     *         payments: array{extras_due_hours: int, wire_window_hours: int, balance_reminder_days: list<int>},
     *         discounts: array{online_deposit_discount_pct: int, max_total_discount_pct: int|null},
     *         holds: array{web_minutes: int, web_extension_minutes: int, near_term_business_hours: int, long_lead_business_days: int, business_days: list<int>, business_day_start: string, business_day_end: string, holidays: list<string>, near_term_max_days: int},
     *         sla: array{response_hours: int, refund_business_days: int, agency_approval_business_days: int},
     *         manifests: array{dpng_fit_days: int, dpng_charter_days: int, captain_days: int, chase_days_before_due: int},
     *         alerts: array{low_occupancy_pct: int, low_occupancy_days_before: int, low_occupancy_min_consecutive_nights: int},
     *         retention: array{passport_months_after_check_out?: int, passport_months_after_cruise?: int, medical_days_after_check_out?: int, medical_days_after_cruise?: int},
     *         cancellation: array{bands: list<array{min_days: int, penalty_pct: int}>, sets: array<string, list<array{min_days: int, penalty_pct: int}>>},
     *         taxes: list<array{code: string, label: string, basis: string, amount: int, child_exempt_under_age: int|null, charged: bool, shown_in_price_panel: bool}>
     *     },
     *     published_at: string,
     *     published_by: array{id: int, name: string}|null,
     *     approval_reference: string|null,
     *     registry: list<array{
     *         key: string,
     *         group: string,
     *         group_label: string,
     *         source_code: string,
     *         name: string,
     *         status: string,
     *         where: string,
     *         paths: list<string>,
     *         source_display: string,
     *         source_value: mixed,
     *         current_display: string,
     *         differs: bool|null,
     *         used_in: string,
     *         lock_reason: string|null,
     *         note: string|null,
     *         link: string|null
     *     }>,
     *     counts: array{all: int, here: int, other_pages: int, locked: int, differs_or_flagged: int}
     * }
     */
    public function toArray(Request $request): array
    {
        $registry = Registry::rows(app(CurrentConfig::class));

        return [
            ...parent::toArray($request),
            'registry' => $registry,
            'counts' => Registry::counts($registry),
        ];
    }
}
