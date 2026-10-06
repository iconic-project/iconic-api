<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{guest_id: int, name: string, room: string, responded: bool} $resource
 */
#[SchemaName('SurveyGuestResource')]
class SurveyGuestResource extends JsonResource
{
    /**
     * @return array{guest_id: int, name: string, room: string, responded: bool}
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
