<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Enums\ConfigKind;
use App\Models\Departure;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\Availability;
use App\Support\Inventory\DepartureSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Departure
 */
class DepartureResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     reference: string,
     *     date: string,
     *     return_date: string,
     *     property_id: int,
     *     itinerary_id: int,
     *     status: string,
     *     urgency_threshold: int,
     *     waitlist_enabled: bool,
     *     public_note: string|null,
     *     festive: bool,
     *     property: array{id: int, code: string, name: string},
     *     itinerary: array{id: int, code: string, name: string, status: string, festive: bool},
     *     rates: array{year: int, suite_from: int|null},
     *     availability: array{
     *         counts: array{sold: int, held: int, blocked: int, free: int, suites_free: int, owner_free: bool},
     *         engine_label: array{code: string, text: string, tone: string},
     *         cabins?: list<array{
     *             cabin: array{code: string, label: string, category: string},
     *             state: string,
     *             claim: array{
     *                 kind: string,
     *                 hold_type: string|null,
     *                 expires_at: string|null,
     *                 holder: array{
     *                     type: string,
     *                     id: int,
     *                     reference: string|null,
     *                     label: string|null,
     *                     detail: array{reason: string, reason_label: string}|array{status: string, type: string, segment: string, display_reference: string|null, owner_id: int, owner_name: string, party_label: string, hold_expired: bool}|null
     *                 }
     *             }|null
     *         }>
     *     },
     *     locks?: array{date_and_property: bool, delete: bool, reason: string|null}
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['property', 'itinerary']);

        $year = (int) $this->date->format('Y');
        $method = $request->route()?->getActionMethod();
        $isDetail = in_array($method, ['show', 'layout', 'store', 'update'], true);
        $withCabins = $isDetail || $request->boolean('with_cabins');
        $withLocks = $isDetail;
        $snapshot = $this->snapshot();

        $payload = [
            'id' => $this->id,
            'reference' => $this->reference,
            'date' => $this->date->toDateString(),
            'return_date' => $this->returnDate()->toDateString(),
            'property_id' => $this->property_id,
            'itinerary_id' => $this->itinerary_id,
            'status' => $this->status->value,
            'urgency_threshold' => $this->urgency_threshold,
            'waitlist_enabled' => $this->waitlist_enabled,
            'public_note' => $this->public_note,
            'festive' => $this->festive,
            'property' => [
                'id' => $this->property->id,
                'code' => $this->property->code,
                'name' => $this->property->name,
            ],
            'itinerary' => [
                'id' => $this->itinerary->id,
                'code' => $this->itinerary->code,
                'name' => $this->itinerary->name,
                'status' => $this->itinerary->status->value,
                'festive' => $this->itinerary->festive,
            ],
            'rates' => [
                'year' => $year,
                'suite_from' => $this->suiteFrom($year),
            ],
            'availability' => [
                'counts' => $snapshot->counts,
                'engine_label' => $snapshot->engineLabel,
            ],
        ];

        if ($withCabins) {
            $payload['availability']['cabins'] = $snapshot->cabins;
        }

        if ($withLocks) {
            $payload['locks'] = $snapshot->locks;
        }

        return $payload;
    }

    private function snapshot(): DepartureSnapshot
    {
        if ($this->resource->snapshot instanceof DepartureSnapshot) {
            return $this->resource->snapshot;
        }

        $computed = app(Availability::class)->forDepartures(collect([$this->resource]));

        return $computed[$this->id];
    }

    private function suiteFrom(int $year): ?int
    {
        $config = app(CurrentConfig::class);

        if (! $config->has(ConfigKind::Rates)) {
            return null;
        }

        return $config->rates()->year($year)?->suitePp;
    }
}
