<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Support\Manifests\ManifestRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ManifestRow
 */
class ManifestDepartureResource extends JsonResource
{
    /**
     * @return array{
     *     departure_id: int,
     *     reference: string,
     *     date: string,
     *     property: string,
     *     charter: bool,
     *     passengers: int,
     *     complete: int,
     *     dpng_due: string,
     *     dpng_offset_days: int,
     *     captain_due: string,
     *     captain_offset_days: int,
     *     status: string,
     *     dpng: ManifestVersionResource|null,
     *     captain: ManifestVersionResource|null
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var ManifestRow $row */
        $row = $this->resource;

        return [
            'departure_id' => $row->departure->id,
            'reference' => $row->departure->reference,
            'date' => $row->departure->date->toDateString(),
            'property' => $row->departure->property->name,
            'charter' => $row->charter,
            'passengers' => $row->passengers,
            'complete' => $row->complete,
            'dpng_due' => $row->due->dpng,
            'dpng_offset_days' => $row->due->dpngDays,
            'captain_due' => $row->due->captain,
            'captain_offset_days' => $row->due->captainDays,
            'status' => $row->status,
            'dpng' => $row->dpng === null ? null : new ManifestVersionResource($row->dpng),
            'captain' => $row->captain === null ? null : new ManifestVersionResource($row->captain),
        ];
    }
}
