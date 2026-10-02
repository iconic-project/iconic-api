<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Models\Departure;
use App\Support\Inventory\DepartureSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Departure $departure
 * @property-read DepartureSnapshot $snapshot
 * @property-read list<string> $offer_codes
 */
class EngineDepartureResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     itinerary: string,
     *     property: string,
     *     embark: string,
     *     disembark: string,
     *     festive: bool,
     *     rate_year: int,
     *     status: string,
     *     suites_free: int,
     *     owner_free: bool,
     *     label: string,
     *     urgency_threshold: int,
     *     waitlist: bool,
     *     note: string|null,
     *     offers: list<string>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{departure: Departure, snapshot: DepartureSnapshot, offer_codes: list<string>} $row */
        $row = $this->resource;
        $departure = $row['departure'];
        $snapshot = $row['snapshot'];

        return [
            'id' => $departure->id,
            'itinerary' => $departure->itinerary->code,
            'property' => $departure->property->code,
            'embark' => $departure->date->toDateString(),
            'disembark' => $departure->returnDate()->toDateString(),
            'festive' => $departure->festive,
            'rate_year' => (int) $departure->date->format('Y'),
            'status' => $departure->status->value,
            'suites_free' => $snapshot->counts['suites_free'],
            'owner_free' => $snapshot->counts['owner_free'],
            'label' => $snapshot->engineLabel['text'],
            'urgency_threshold' => $departure->urgency_threshold,
            'waitlist' => $departure->waitlist_enabled,
            'note' => $departure->public_note,
            'offers' => $row['offer_codes'],
        ];
    }
}
