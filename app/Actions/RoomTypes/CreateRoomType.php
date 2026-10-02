<?php

declare(strict_types=1);

namespace App\Actions\RoomTypes;

use App\Actions\Action;
use App\Enums\RoomTypeStatus;
use App\Models\Property;
use App\Models\RoomType;
use App\Support\History\History;

final class CreateRoomType extends Action
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Property $property, array $data): RoomType
    {
        return $this->transaction(function () use ($property, $data): RoomType {
            $type = new RoomType;
            $type->fill($data);
            $type->property_id = $property->id;
            $type->status = RoomTypeStatus::Active;
            $type->waitlist_enabled = (bool) ($data['waitlist_enabled'] ?? true);
            $type->sort = (int) ($data['sort'] ?? 0);
            $type->save();

            History::record($type, 'room_type.created', after: [
                'code' => $type->code,
                'name' => $type->name,
                'base_occupancy' => $type->base_occupancy,
                'max_occupancy' => $type->max_occupancy,
                'max_adults' => $type->max_adults,
                'max_children' => $type->max_children,
                'waitlist_enabled' => $type->waitlist_enabled,
                'status' => $type->status->value,
            ]);

            return $type;
        });
    }
}
