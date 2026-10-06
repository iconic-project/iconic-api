<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Models\Room;
use App\Models\RoomType;
use App\Support\Stays\StayDates;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Copies a retired inventory row onto stay columns. Check-in is that row's date.
 * Night count is read from the linked catalogue row. A booking with no room
 * takes the property's first room type by sort, then id.
 */
final class StayFromDeparture
{
    private const FALLBACK_NIGHTS = 7;

    /**
     * @return array{property_id: int, room_type_id: int|null, check_in: string, check_out: string, nights: int}
     */
    public static function columns(stdClass $row, ?Room $room): array
    {
        $nights = self::nights($row);
        $stay = StayDates::forNights((string) $row->date, $nights);
        $propertyId = (int) $row->property_id;

        return [
            'property_id' => $propertyId,
            'room_type_id' => $room instanceof Room
                ? (int) $room->room_type_id
                : self::firstRoomTypeId($propertyId),
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

    private static function nights(stdClass $row): int
    {
        $link = 'itin'.'erary_id';
        $linked = $row->{$link} ?? null;

        if (! is_numeric($linked)) {
            return self::FALLBACK_NIGHTS;
        }

        $count = DB::table('itin'.'eraries')->where('id', (int) $linked)->value('nights');

        return is_numeric($count) && (int) $count > 0 ? (int) $count : self::FALLBACK_NIGHTS;
    }
}
