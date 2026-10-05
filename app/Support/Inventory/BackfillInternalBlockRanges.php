<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\InternalBlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fills property_id, starts_on and ends_on from the block's night claims.
 * ends_on is the day after the last claimed night.
 */
final class BackfillInternalBlockRanges
{
    public static function run(): void
    {
        $ids = DB::table('internal_blocks')->pluck('id');

        foreach ($ids as $id) {
            $span = DB::table('room_night_claims')
                ->where('holder_type', (new InternalBlock)->getMorphClass())
                ->where('holder_id', $id)
                ->selectRaw('MIN(night) as starts_on, MAX(night) as last_night')
                ->first();

            if ($span === null || $span->starts_on === null || $span->last_night === null) {
                continue;
            }

            $propertyId = DB::table('room_night_claims')
                ->join('rooms', 'rooms.id', '=', 'room_night_claims.room_id')
                ->where('room_night_claims.holder_type', (new InternalBlock)->getMorphClass())
                ->where('room_night_claims.holder_id', $id)
                ->whereDate('room_night_claims.night', $span->starts_on)
                ->orderBy('rooms.sort')
                ->orderBy('rooms.id')
                ->value('rooms.property_id');

            if ($propertyId === null) {
                continue;
            }

            DB::table('internal_blocks')->where('id', $id)->update([
                'property_id' => $propertyId,
                'starts_on' => $span->starts_on,
                'ends_on' => CarbonImmutable::parse((string) $span->last_night)->addDay()->toDateString(),
            ]);
        }
    }
}
