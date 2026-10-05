<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Models\CabinClaim;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Expands each cabin claim into one room-night row per night of the departure stay.
 * Idempotent: a source that already has any night row is skipped.
 */
final class BackfillRoomNightClaims
{
    public function run(): int
    {
        $inserted = 0;

        CabinClaim::query()
            ->with(['departure.itinerary'])
            ->chunkById(500, function (Collection $claims) use (&$inserted): void {
                foreach ($claims as $claim) {
                    $inserted += $this->insertClaim($claim);
                }
            });

        return $inserted;
    }

    private function insertClaim(CabinClaim $claim): int
    {
        $already = DB::table('room_night_claims')
            ->where('legacy_cabin_claim_id', $claim->id)
            ->exists();

        if ($already) {
            return 0;
        }

        $attributes = $claim->getAttributes();
        $group = (string) Str::uuid();
        $rows = [];

        foreach ($claim->departure->stayDates()->eachNight() as $night) {
            $rows[] = [
                'room_id' => $claim->room_id,
                'night' => $night->toDateString(),
                'holder_type' => $attributes['holder_type'],
                'holder_id' => $attributes['holder_id'],
                'kind' => $attributes['kind'],
                'hold_type' => $attributes['hold_type'],
                'expires_at' => $attributes['expires_at'],
                'released_at' => $attributes['released_at'],
                'release_reason' => $attributes['release_reason'],
                'claim_group' => $group,
                'legacy_cabin_claim_id' => $claim->id,
                'created_at' => $attributes['created_at'],
                'updated_at' => $attributes['updated_at'],
                'created_by' => $attributes['created_by'],
                'updated_by' => $attributes['updated_by'],
            ];
        }

        DB::table('room_night_claims')->insert($rows);

        return count($rows);
    }
}
