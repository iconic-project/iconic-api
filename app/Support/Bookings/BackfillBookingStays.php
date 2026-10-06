<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * Copies each booking's departure onto stay columns, then renames retired statuses.
 * History rows are left as written.
 */
final class BackfillBookingStays
{
    /**
     * @return list<string>
     */
    public function run(): array
    {
        $this->statuses();

        return $this->stays();
    }

    public function statuses(): void
    {
        DB::table('bookings')->where('status', 'ON_BOARD')->update(['status' => BookingStatus::InHouse->value]);
        DB::table('bookings')->where('status', 'COMPLETED')->update(['status' => BookingStatus::CheckedOut->value]);
    }

    /**
     * Bookings with no room, listed by reference. Their room type is the property's first by sort.
     *
     * @return list<string>
     */
    public function stays(): array
    {
        $fallback = [];

        Booking::query()
            ->whereNull('check_in')
            ->with(['property', 'room'])
            ->orderBy('id')
            ->chunkById(200, function ($bookings) use (&$fallback): void {
                foreach ($bookings as $booking) {
                    $fallback = array_merge($fallback, $this->fill($booking));
                }
            });

        $open = DB::table('bookings')
            ->where(function ($query): void {
                $query->whereNull('check_in')
                    ->orWhereNull('check_out')
                    ->orWhereNull('nights')
                    ->orWhereNull('property_id');
            })
            ->count();

        if ($open > 0) {
            throw new RuntimeException($open.' bookings have no stay after backfill.');
        }

        return $fallback;
    }

    /**
     * @return list<string>
     */
    private function fill(Booking $booking): array
    {
        $linkedId = $booking->getAttribute('departure_id');
        $row = is_numeric($linkedId)
            ? DB::table('departures')->where('id', (int) $linkedId)->first()
            : null;

        if (! $row instanceof stdClass) {
            throw new RuntimeException('Booking '.$booking->getKey().' has no departure to backfill.');
        }

        $room = $booking->room instanceof Room ? $booking->room : null;
        $columns = StayFromDeparture::columns($row, $room);

        if ($columns['room_type_id'] === null) {
            $label = $booking->reference ?? 'id:'.$booking->id;

            throw new RuntimeException('Booking '.$label.' has no room and its property has no room type.');
        }

        DB::table('bookings')->where('id', $booking->id)->update($columns);

        if (! StayFromDeparture::usedRoomTypeFallback($room)) {
            return [];
        }

        $reference = $booking->reference;

        return [is_string($reference) && $reference !== '' ? $reference : 'id:'.$booking->id];
    }
}
