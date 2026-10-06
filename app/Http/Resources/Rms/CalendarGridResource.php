<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Retired inventory grid. The panel reads NightCalendarResource.
 *
 * @property array<string, mixed> $resource
 *
 * @deprecated Panel 17-07 reads NightCalendarResource.
 */
class CalendarGridResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;

        return $payload;
    }
}
