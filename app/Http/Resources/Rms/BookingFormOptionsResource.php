<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     main: list<array{value: string, label: string, trade: bool}>,
 *     origin: list<array{group: string, options: list<array{value: string, label: string}>}>,
 *     preferred: list<array{value: string, label: string}>,
 *     guests: array<string, int|bool|string>,
 *     commission: array{cap_pct: int, default_pct: int},
 *     payments: array{wire_window_hours: int},
 *     agencies: list<array{id: int, reference: string, name: string, network: string|null, commission_pct: int}>,
 *     room_types: list<array{id: int, code: string, name: string, property_id: int, base_occupancy: int, max_occupancy: int, max_adults: int, max_children: int, restrictions: list<string>}>,
 *     rate_plans: list<array{code: string, name: string, default: bool, deposit_pct: int, balance_days: int, refundable: bool}>,
 *     stay: array{min_nights: int, max_nights: int, max_rooms_per_booking: int, booking_horizon_days: int}
 * } $resource
 */
class BookingFormOptionsResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     main: list<array{value: string, label: string, trade: bool}>,
     *     origin: list<array{group: string, options: list<array{value: string, label: string}>}>,
     *     preferred: list<array{value: string, label: string}>,
     *     guests: array<string, int|bool|string>,
     *     commission: array{cap_pct: int, default_pct: int},
     *     payments: array{wire_window_hours: int},
     *     agencies: list<array{id: int, reference: string, name: string, network: string|null, commission_pct: int}>,
     *     room_types: list<array{id: int, code: string, name: string, property_id: int, base_occupancy: int, max_occupancy: int, max_adults: int, max_children: int, restrictions: list<string>}>,
     *     rate_plans: list<array{code: string, name: string, default: bool, deposit_pct: int, balance_days: int, refundable: bool}>,
     *     stay: array{min_nights: int, max_nights: int, max_rooms_per_booking: int, booking_horizon_days: int}
     * }
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
