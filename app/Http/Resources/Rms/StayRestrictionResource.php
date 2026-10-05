<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\StayRestriction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One night of sell rules.
 *
 * canBook reasons, stable order:
 * STOP_SELL, CLOSED_TO_ARRIVAL, CLOSED_TO_DEPARTURE, MIN_STAY:n, MAX_STAY:n,
 * SOLD_OUT, NO_SINGLE_ROOM.
 * n is the threshold, not the length of the stay. ok is false when any reason applies.
 * A night that is short on rooms is SOLD_OUT only, not also NO_SINGLE_ROOM.
 *
 * from and to on the restrictions payload are inclusive. A stay is still
 * [check_in, check_out). room_type_id null is property-wide.
 *
 * @mixin StayRestriction
 */
class StayRestrictionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Documented canBook order. MIN_STAY:n and MAX_STAY:n stand in for the
     * numbered codes (MIN_STAY:3, MAX_STAY:30).
     *
     * @var list<string>
     */
    public const REASON_ORDER = [
        'STOP_SELL',
        'CLOSED_TO_ARRIVAL',
        'CLOSED_TO_DEPARTURE',
        'MIN_STAY:n',
        'MAX_STAY:n',
        'SOLD_OUT',
        'NO_SINGLE_ROOM',
    ];

    /**
     * @return array{
     *     night: string,
     *     room_type_id: int|null,
     *     stop_sell: bool,
     *     closed_to_arrival: bool,
     *     closed_to_departure: bool,
     *     min_stay: int|null,
     *     max_stay: int|null,
     *     note: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'night' => $this->night->toDateString(),
            'room_type_id' => $this->room_type_id,
            'stop_sell' => $this->stop_sell,
            'closed_to_arrival' => $this->closed_to_arrival,
            'closed_to_departure' => $this->closed_to_departure,
            'min_stay' => $this->min_stay,
            'max_stay' => $this->max_stay,
            'note' => $this->note,
        ];
    }
}
