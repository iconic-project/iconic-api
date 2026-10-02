<?php

declare(strict_types=1);

namespace App\Actions\Rooms;

use App\Actions\Action;
use App\Models\Room;
use App\Support\History\History;

final class UpdateRoom extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Room $room, array $data): Room
    {
        unset($data['property_id'], $data['status']);

        return $this->transaction(function () use ($room, $data): Room {
            $room->fill($data);

            if (! $room->isDirty()) {
                return $room;
            }

            $room->save();

            [$before, $after] = History::diff($room);

            if ($before !== []) {
                History::record($room, 'room.updated', $before, $after);
            }

            return $room;
        });
    }
}
