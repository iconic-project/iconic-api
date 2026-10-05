<?php

declare(strict_types=1);

namespace App\Http\Resources\Rms;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfirmRequestPreviewResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     alternative: bool,
     *     room: array{id: int, code: string, label: string},
     *     room_type: array{id: int, code: string, name: string}
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{alternative: bool, room: Room} $payload */
        $payload = $this->resource;
        $room = $payload['room'];
        $room->loadMissing('roomType');
        $type = $room->roomType;

        return [
            'alternative' => $payload['alternative'],
            'room' => [
                'id' => $room->id,
                'code' => $room->code,
                'label' => $room->label,
            ],
            'room_type' => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
            ],
        ];
    }
}
