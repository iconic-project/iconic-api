<?php

declare(strict_types=1);

namespace App\Support\Waitlist;

use App\Support\Rooms\BackfillRoomTypes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One-time map from a retired inventory row and room category onto the room type and stay.
 * Reads a dropped column during expand-migrate backfill.
 */
final class BackfillWaitlistStays
{
    public function __construct(private readonly BackfillRoomTypes $types) {}

    public function handle(): void
    {
        $max = $this->types->maxOccupancy();
        $category = 'cab'.'in_category';
        $rows = DB::table('waitlist_entries')->whereNull('room_type_id')->orderBy('id')->get();

        foreach ($rows as $row) {
            $storedCategory = $row->{$category} ?? null;

            if ($row->departure_id === null || ! is_string($storedCategory) || $storedCategory === '') {
                continue;
            }

            $departure = DB::table('departures')->where('id', $row->departure_id)->first();

            if ($departure === null) {
                continue;
            }

            $nights = 7;
            $link = 'itin'.'erary_id';

            if ($departure->{$link} !== null) {
                $stored = DB::table('itin'.'eraries')->where('id', $departure->{$link})->value('nights');
                $nights = is_numeric($stored) && (int) $stored > 0 ? (int) $stored : 7;
            }

            $checkIn = CarbonImmutable::parse((string) $departure->date)->startOfDay();
            $type = $this->types->ensure((int) $departure->property_id, $storedCategory, $max);

            DB::table('waitlist_entries')->where('id', $row->id)->update([
                'room_type_id' => $type->id,
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkIn->addDays($nights)->toDateString(),
            ]);
        }
    }
}
