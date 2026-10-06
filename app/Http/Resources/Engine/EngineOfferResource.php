<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Offer
 */
class EngineOfferResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     code: string,
     *     type: string,
     *     value: int|null,
     *     booking_window: array{0: string|null, 1: string|null},
     *     travel_window: array{0: string|null, 1: string|null},
     *     combinable: bool,
     *     badge: string|null,
     *     show_on_card: bool,
     *     show_on_calendar: bool,
     *     price_line: string|null,
     *     terms: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'type' => $this->type->value,
            'value' => $this->value,
            'booking_window' => [
                $this->booking_from?->toDateString(),
                $this->booking_to?->toDateString(),
            ],
            'travel_window' => [
                $this->stay_from?->toDateString(),
                $this->stay_to?->toDateString(),
            ],
            'combinable' => $this->combinable,
            'badge' => $this->badge,
            'show_on_card' => $this->show_on_card,
            'show_on_calendar' => $this->show_on_calendar,
            'price_line' => $this->price_line,
            'terms' => $this->terms,
        ];
    }
}
