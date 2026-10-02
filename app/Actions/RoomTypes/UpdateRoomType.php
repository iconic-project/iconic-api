<?php

declare(strict_types=1);

namespace App\Actions\RoomTypes;

use App\Actions\Action;
use App\Models\RoomType;
use App\Support\History\History;

final class UpdateRoomType extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(RoomType $type, array $data): RoomType
    {
        unset($data['code'], $data['property_id'], $data['status']);

        return $this->transaction(function () use ($type, $data): RoomType {
            $type->fill($data);

            if (! $type->isDirty()) {
                return $type;
            }

            $type->save();

            [$before, $after] = History::diff($type);

            if ($before !== []) {
                History::record($type, 'room_type.updated', $before, $after);
            }

            return $type;
        });
    }
}
