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
     *         registration: array{fields: list<string>, formats: list<string>, deadline_hours_after_check_in: int|null},
     *         alerts: array{low_occupancy_pct: int, low_occupancy_days_before: int, low_occupancy_min_consecutive_nights: int},
     *         nps: array{survey_hours_after_check_out?: int, survey_hours_after_return?: int, alert_below: int, review_request_from: int, review_url: string},
     *         retention: array{passport_months_after_check_out: int, medical_days_after_check_out: int, behavioural_raw_months: int, behavioural_unstitched_days: int},
     *         legal: array{consent_versions: array{terms: string, cancellation: string, privacy: string, insurance: string, marketing: string, analytics: string, checkout_marketing: string}},
     *         legal_entity: array{name: string, address_lines: list<string>, email: string, website: string, ein: string, bank: array{bank_name: string, account_name: string, account_number: string, routing: string, swift: string}},
     *         documents: array{pre_arrival_days_before: int, voucher_days_before: int},
     *         crm: array{segment_high_ltv: int, segment_mid_ltv: int, pipeline: array{sla_new_lead_business_hours: int, sla_qualifying_business_days: int, sla_negotiation_business_days: int, probability_new_lead: int, probability_qualifying: int, probability_quoted: int, probability_negotiation: int, probability_deposit_pending: int}},
     *         privacy: array{request_sla_days: int},
     *         reports: array{retention_days: int, pickup_days: int},
     *         charter?: array<string, mixed>,
     *         portal: array{invite_valid_days: int},
     *         stay: array{check_in_time: string, check_out_time: string, no_show_cutoff_time: string, min_nights: int, max_nights: int, max_rooms_per_booking: int, check_in_requires_full_payment: bool, booking_horizon_days: int},
     *         cancellation: array{bands: list<array{min_days: int, penalty_pct: int}>, charter_bands: list<array{min_days: int, penalty_pct: int}>, sets: array<string, list<array{min_days: int, penalty_pct: int}>>},
     *         taxes?: list<array{code: string, label: string, basis: string, amount: int, child_exempt_under_age: int|null, charged: bool, shown_in_price_panel: bool}>
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
