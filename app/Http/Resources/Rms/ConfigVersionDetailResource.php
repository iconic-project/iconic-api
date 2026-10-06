<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\ConfigVersion;
use App\Models\User;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConfigVersion
 */
class ConfigVersionDetailResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Shared by rates, business-rules and engine-settings version show/store.
     * `document` is a union (OpenAPI oneOf) of the three published shapes.
     *
     * @return array{
     *     version: int,
     *     document: array{
     *         currency: string,
     *         schema_version: int,
     *         seasons: list<array{code: string, name: string, from: string, to: string}>,
     *         room_rates: list<array{room_type: string, season: string, nightly: int}>,
     *         occupancy: array{extra_adult_nightly: int, extra_child_nightly: int, single_occupancy_pct: int},
     *         day_of_week: array<string, int>,
     *         length_of_stay: list<array{min_nights: int, discount_pct: int}>,
     *         supplements: list<array{code: string, label: string, from: string, to: string, per_night: int, basis: string}>,
     *         rate_plans: list<array{code: string, name: string, default: bool, adjust_pct: int, refundable: bool, deposit_pct: int, balance_days: int, cancellation: string, meal_plan: string}>
     *     }|array{
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
     *     }|array{
     *         guests: array{
     *             max_per_property: int,
     *             child_min_age: int,
     *             child_max_age: int,
     *             adult_required_with_children: bool,
     *             under_age_message: string
     *         },
     *         calendar: array{
     *             default_search_from: string,
     *             default_search_to: string,
     *             default_adults: int,
     *             horizon_months: int
     *         },
     *         locale: array{default: string, live: list<string>, currency: string},
     *         fees: array{
     *             show_in_price_panel: bool,
     *             footnote: string
     *         },
     *         copy: array{
     *             book_now_pay_later: string,
     *             traveling_with_children: string,
     *             solo_and_triple: string,
     *             pay_today: string,
     *             details_note: string,
     *             confirmation_steps: list<string>,
     *             online_deposit_advantage: string,
     *             online_deposit_perk: string
     *         },
     *         availability: array{low_availability_threshold: int},
     *         charter?: array<string, mixed>
     *     },
     *     published_at: string,
     *     published_by: array{id: int, name: string}|null,
     *     approval_reference: string|null,
     *     changes: list<array{path: string, label: string, from: mixed, to: mixed}>
     * }
     */
    public function toArray(Request $request): array
    {
        $publisher = $this->publisher;

        return [
            'version' => $this->version,
            'document' => $this->asDocument()->toArray(),
            'published_at' => Iso::utc($this->published_at),
            'published_by' => $publisher instanceof User
                ? ['id' => $publisher->id, 'name' => $publisher->name]
                : null,
            'approval_reference' => $this->approval_reference,
            'changes' => $this->changes,
        ];
    }
}
