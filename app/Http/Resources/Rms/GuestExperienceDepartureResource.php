<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{departure_id: int, date: string, property: string, passengers: int} $resource
 */
#[SchemaName('GuestExperienceDepartureResource')]
class GuestExperienceDepartureResource extends JsonResource
{
    /**
     * @return array{departure_id: int, date: string, property: string, passengers: int}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
