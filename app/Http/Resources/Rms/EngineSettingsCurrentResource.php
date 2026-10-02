<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Support\Config\Documents\EngineSettingsDocument;
use Illuminate\Http\Request;

class EngineSettingsCurrentResource extends ConfigCurrentResource
{
    /**
     * @return array{
     *     version: int,
     *     document: array{
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
     *     copy_paths: list<string>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'copy_paths' => EngineSettingsDocument::copyPaths(),
        ];
    }
}
