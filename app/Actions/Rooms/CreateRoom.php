<?php

declare(strict_types=1);

namespace App\Actions\Rooms;

use App\Actions\Action;
use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Support\History\History;

final class CreateRoom extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Property $property, array $data): Room
    {
        return $this->transaction(function () use ($property, $data): Room {
            $room = new Room;
            $room->fill($data);
            $room->property_id = $property->id;
            $room->status = RoomStatus::Active;
            $room->sort = (int) ($data['sort'] ?? 0);
            $room->save();

            History::record($room, 'room.created', after: [
                'code' => $room->code,
                'label' => $room->label,
                'floor' => $room->floor,
                'room_type_id' => $room->room_type_id,
                'sort' => $room->sort,
                'status' => $room->status->value,
            ]);

            return $room;
        });
    }
}
