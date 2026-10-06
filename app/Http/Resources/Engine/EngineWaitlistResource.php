<?php

declare(strict_types=1);

namespace App\Http\Resources\Engine;

use App\Models\WaitlistEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WaitlistEntry
 */
class EngineWaitlistResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: int,
     *     room_type: string,
     *     check_in: string,
     *     check_out: string,
     *     source: string
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('roomType');

        return [
            'id' => $this->id,
            'room_type' => $this->roomType->code,
            'check_in' => $this->check_in->toDateString(),
            'check_out' => $this->check_out->toDateString(),
            'source' => $this->source->value,
        ];
    }
}
