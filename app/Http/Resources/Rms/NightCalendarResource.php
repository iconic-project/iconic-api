<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Services\Inventory\NightGrid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property NightGrid $resource
 */
class NightCalendarResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     property: array{id: int, code: string, name: string},
     *     from: string,
     *     to: string,
     *     nights: list<string>,
     *     rooms: list<array<string, mixed>>,
     *     counts: array<string, array<string, array{total: int, free: int, held: int, sold: int, blocked: int}>>,
     *     occupancy: array{room_nights_available: int, room_nights_sold: int, pct: int},
     *     kpis: array{occupancy_pct: int, free_room_nights: int, nights_fully_sold: int, nights_below_threshold: int}
     * }
     */
    public function toArray(Request $request): array
    {
        $grid = $this->resource;

        return [
            'property' => $grid->property,
            'from' => $grid->from,
            'to' => $grid->to,
            'nights' => $grid->nights,
            'rooms' => $grid->rooms,
            'counts' => $grid->counts,
            'occupancy' => $grid->occupancy,
            'kpis' => $grid->kpis,
        ];
    }
}
