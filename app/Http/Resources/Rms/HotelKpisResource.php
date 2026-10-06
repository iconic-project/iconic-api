<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     today: string,
 *     pickup_days: int,
 *     property: int|null,
 *     room_type: int|null,
 *     channel: string|null,
 *     periods: list<array<string, mixed>>
 * } $resource
 */
#[SchemaName('HotelKpisResource')]
class HotelKpisResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     today: string,
     *     pickup_days: int,
     *     property: int|null,
     *     room_type: int|null,
     *     channel: string|null,
     *     periods: list<array<string, mixed>>
     * }
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
