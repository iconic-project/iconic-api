<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\Departure;
use App\Support\Departures\Warnings;
use Illuminate\Http\Request;

/**
 * @mixin Departure
 */
class DepartureMutationResource extends DepartureResource
{
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
     *     locks?: array{date_and_property: bool, delete: bool, reason: string|null},
     *     warnings: list<string>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'warnings' => Warnings::for($this->resource),
        ];
    }
}
