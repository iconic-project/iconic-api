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
     *         years: list<array{year: int, suite_pp: int, owner_pp: int, charter_week: int}>,
     *         terms: array{
     *             cabin_deposit_pct: int,
     *             cabin_balance_days: int,
     *             charter_deposit_pct: int,
     *             charter_deposit_business_days: int,
     *             charter_balance_days: int
     *         },
     *         rules: array{
     *             single_supplement_pct: int,
     *             triple_discount_pct: int,
     *             child_discount_pct: int,
     *             child_discounts_per_adult: int,
     *             child_discounts_per_cabin: int,
     *             back_to_back_pct: int,
     *             festive_supplement_pp: int,
     *             festive_supplement_charter: int
     *         },
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
     *         manifests: array{dpng_fit_days: int, dpng_charter_days: int, captain_days: int, chase_days_before_due: int},
     *         alerts: array{low_occupancy_pct: int, low_occupancy_days_before: int, low_occupancy_min_consecutive_nights: int},
     *         retention: array{passport_months_after_check_out?: int, passport_months_after_cruise?: int, medical_days_after_check_out?: int, medical_days_after_cruise?: int},
     *         cancellation: array{bands: list<array{min_days: int, penalty_pct: int}>, sets: array<string, list<array{min_days: int, penalty_pct: int}>>},
     *         taxes: list<array{code: string, label: string, basis: string, amount: int, child_exempt_under_age: int|null, charged: bool, shown_in_price_panel: bool}>
     *     }|array{
     *         guests: array{
     *             max_per_cabin: int,
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
     *             tct_pp: int,
     *             png: array{
     *                 foreign_over_12: int,
     *                 foreign_12_and_under: int,
     *                 can_adult: int,
     *                 can_minor: int,
     *                 national_or_resident: int,
     *                 exempt_under_age: int
     *             },
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
     *         charter: array{
     *             headline: string,
     *             intro: string,
     *             itinerary_label: string,
     *             response_sla_hours: int,
     *             group_contexts: list<string>,
     *             thank_you: string
     *         }
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
