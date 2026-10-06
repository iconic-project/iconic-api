<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\Offer;
use App\Support\Iso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Offer
 */
class OfferResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     reference: string,
     *     code: string,
     *     name: string,
     *     type: string,
     *     value: int|null,
     *     value_text: string|null,
     *     channel: string,
     *     partner: string|null,
     *     booking_from: string|null,
     *     booking_to: string|null,
     *     stay_from: string|null,
     *     stay_to: string|null,
     *     min_nights: int|null,
     *     applies_to_room_types: list<string>|null,
     *     applies_to_rate_plans: list<string>|null,
     *     combinable: bool,
     *     is_promo_code: bool,
     *     badge: string|null,
     *     show_on_card: bool,
     *     show_on_calendar: bool,
     *     price_line: string|null,
     *     terms: string|null,
     *     status: string,
     *     stored_status: string,
     *     approved_by: array{id: int, name: string}|null,
     *     approved_at: string|null,
     *     approval_reason: string|null,
     *     benefit_label: string,
     *     scope_label: string,
     *     booking_window_label: string,
     *     travel_window_label: string,
     *     stay_window_label: string,
     *     engine_placement: string
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('approvedBy');

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'value' => $this->value,
            'value_text' => $this->value_text,
            'channel' => $this->channel->value,
            'partner' => $this->partner,
            'booking_from' => $this->booking_from?->toDateString(),
            'booking_to' => $this->booking_to?->toDateString(),
            'stay_from' => $this->stay_from?->toDateString(),
            'stay_to' => $this->stay_to?->toDateString(),
            'min_nights' => $this->min_nights,
            'applies_to_room_types' => $this->applies_to_room_types,
            'applies_to_rate_plans' => $this->applies_to_rate_plans,
            'combinable' => $this->combinable,
            'is_promo_code' => $this->is_promo_code,
            'badge' => $this->badge,
            'show_on_card' => $this->show_on_card,
            'show_on_calendar' => $this->show_on_calendar,
            'price_line' => $this->price_line,
            'terms' => $this->terms,
            'status' => $this->derivedStatus(),
            'stored_status' => $this->status->value,
            'approved_by' => $this->approvedBy === null ? null : [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ],
            'approved_at' => $this->approved_at !== null ? Iso::utc($this->approved_at) : null,
            'approval_reason' => $this->approval_reason,
            'benefit_label' => $this->benefitLabel(),
            'scope_label' => $this->scopeLabel(),
            'booking_window_label' => $this->bookingWindowLabel(),
            'travel_window_label' => $this->travelWindowLabel(),
            'stay_window_label' => $this->stayWindowLabel(),
            'engine_placement' => $this->enginePlacement(),
        ];
    }
}
