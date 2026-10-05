<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Models\Departure;
use App\Models\Room;
use App\Models\RoomType;

/**
 * A yacht departure becomes a stay. Check-in is the departure date.
 * A booking with no room takes the property's first room type by sort, then id.
 */
final class StayFromDeparture
{
    /**
     * @return array{property_id: int, room_type_id: int|null, check_in: string, check_out: string, nights: int}
     */
    public static function columns(Departure $departure, ?Room $room): array
    {
        $stay = $departure->stayDates();

        return [
            'property_id' => (int) $departure->property_id,
            'room_type_id' => $room instanceof Room
                ? (int) $room->room_type_id
                : self::firstRoomTypeId((int) $departure->property_id),
            'check_in' => $stay->checkIn()->toDateString(),
            'check_out' => $stay->checkOut()->toDateString(),
            'nights' => $stay->nights(),
        ];
    }

    public static function usedRoomTypeFallback(?Room $room): bool
    {
        return ! $room instanceof Room;
    }

    public static function firstRoomTypeId(int $propertyId): ?int
    {
        $id = RoomType::query()
            ->where('property_id', $propertyId)
            ->orderBy('sort')
            ->orderBy('id')
            ->value('id');

        return is_int($id) ? $id : null;
    }
}
