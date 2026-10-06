<?php

declare(strict_types=1);

namespace App\Support\Waitlist;

use App\Support\Rooms\BackfillRoomTypes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One-time map from a departure and cabin category onto the room type and stay.
 * The departure columns stay until sprint 22. This is the only waitlist reader of them.
 */
final class BackfillWaitlistStays
{
    public function __construct(private readonly BackfillRoomTypes $types) {}

    public function handle(): void
    {
        $max = $this->types->maxPerCabin();
        $rows = DB::table('waitlist_entries')->whereNull('room_type_id')->orderBy('id')->get();

        foreach ($rows as $row) {
            if ($row->departure_id === null || ! is_string($row->cabin_category) || $row->cabin_category === '') {
                continue;
            }

            $departure = DB::table('departures')->where('id', $row->departure_id)->first();

            if ($departure === null) {
                continue;
            }

            $nights = 7;

            if ($departure->itinerary_id !== null) {
                $stored = DB::table('itineraries')->where('id', $departure->itinerary_id)->value('nights');
                $nights = is_numeric($stored) && (int) $stored > 0 ? (int) $stored : 7;
            }

            $checkIn = CarbonImmutable::parse((string) $departure->date)->startOfDay();
            $type = $this->types->ensure((int) $departure->property_id, $row->cabin_category, $max);

            DB::table('waitlist_entries')->where('id', $row->id)->update([
                'room_type_id' => $type->id,
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkIn->addDays($nights)->toDateString(),
            ]);
        }
    }
}
