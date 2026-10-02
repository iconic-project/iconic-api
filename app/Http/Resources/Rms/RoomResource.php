<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Room
 */
class RoomResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('roomType');

        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'code' => $this->code,
            'label' => $this->label,
            'floor' => $this->floor,
            'sort' => $this->sort,
            'status' => $this->status->value,
            'room_type' => [
                'id' => $this->roomType->id,
                'code' => $this->roomType->code,
                'name' => $this->roomType->name,
            ],
        ];
    }
}
