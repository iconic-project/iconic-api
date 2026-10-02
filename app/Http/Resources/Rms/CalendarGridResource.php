<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{departures: list<array<string, mixed>>, rows: list<array<string, mixed>>} $resource
 */
class CalendarGridResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     departures: list<array{
     *         id: int,
     *         reference: string,
     *         date: string,
     *         property: array{id: int, code: string, name: string},
     *         itinerary: array{id: int, code: string, name: string},
     *         festive: bool,
     *         status: string
     *     }>,
     *     rows: list<array{
     *         property: array{id: int, code: string, name: string},
     *         cabin: array{id: int, code: string, label: string, category: string, sort: int},
     *         cells: array<string, array{
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
     *     }>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{departures: list<array<string, mixed>>, rows: list<array<string, mixed>>} $payload */
        $payload = $this->resource;

        return $payload;
    }
}
