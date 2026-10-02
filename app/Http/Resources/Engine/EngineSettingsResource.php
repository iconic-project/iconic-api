<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Config\Documents\EngineSettingsDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EngineSettingsResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     guests: array{
     *         max_per_cabin: int,
     *         max_per_property: int,
     *         child_min_age: int,
     *         child_max_age: int,
     *         adult_required_with_children: bool,
     *         under_age_message: string
     *     },
     *     policies: array{
     *         web_hold_minutes: int,
     *         web_hold_extension_minutes: int,
     *         hold_near_business_hours: int,
     *         hold_long_lead_business_days: int,
     *         response_sla_hours: int,
     *         modification_fee_usd: int,
     *         extras_due_hours: int
     *     },
     *     legal: array{
     *         consent_versions: array{
     *             terms: string,
     *             cancellation: string,
     *             privacy: string,
     *             insurance: string,
     *             marketing: string,
     *             analytics: string,
     *             checkout_marketing: string
     *         }
     *     },
     *     calendar: array{
     *         default_search_from: string,
     *         default_search_to: string,
     *         default_adults: int,
     *         horizon_months: int,
     *         first_bookable_month: string
     *     },
     *     locale: array{default: string, live: list<string>, currency: string},
     *     copy: array{
     *         book_now_pay_later: string,
     *         traveling_with_children: string,
     *         solo_and_triple: string,
     *         pay_today: string,
     *         details_note: string,
     *         confirmation_steps: list<string>,
     *         online_deposit_advantage: string,
     *         online_deposit_perk: string
     *     },
     *     fees: array{
     *         tct_pp: int,
     *         png: array{
     *             foreign_over_12: int,
     *             foreign_12_and_under: int,
     *             can_adult: int,
     *             can_minor: int,
     *             national_or_resident: int,
     *             exempt_under_age: int
     *         },
     *         show_in_price_panel: bool,
     *         footnote: string
     *     },
     *     charter: array{
     *         headline: string,
     *         intro: string,
     *         itinerary_label: string,
     *         response_sla_hours: int,
     *         group_contexts: list<string>,
     *         thank_you: string,
     *         capacity: int
     *     }
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{settings: EngineSettingsDocument, rules: BusinessRulesDocument} $row */
        $row = $this->resource;
        $settings = $row['settings'];
        $rules = $row['rules'];
        $calendar = $settings->calendar->toArray();
        $charter = $settings->charter->toArray();

        return [
            'guests' => $settings->guests->toArray(),
            'policies' => [
                'web_hold_minutes' => $rules->holds->webMinutes,
                'web_hold_extension_minutes' => $rules->holds->webExtensionMinutes,
                'hold_near_business_hours' => $rules->holds->nearTermBusinessHours,
                'hold_long_lead_business_days' => $rules->holds->longLeadBusinessDays,
                'response_sla_hours' => $rules->sla->responseHours,
                'modification_fee_usd' => $rules->modificationFeeUsd,
                'extras_due_hours' => $rules->payments->extrasDueHours,
            ],
            'legal' => [
                'consent_versions' => $rules->consentVersions->toArray(),
            ],
            'calendar' => [
                ...$calendar,
                'first_bookable_month' => $calendar['default_search_from'],
            ],
            'locale' => $settings->locale->toArray(),
            'copy' => $settings->copy->toArray(),
            'fees' => $settings->fees->toArray(),
            'charter' => [
                ...$charter,
                'capacity' => $settings->guests->maxPerProperty,
            ],
        ];
    }
}
