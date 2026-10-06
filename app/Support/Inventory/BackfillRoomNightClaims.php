<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Support\Stays\StayDates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Expands each archived inventory claim into one room-night row per night.
 * Idempotent: a source that already has any night row is skipped.
 * Runs while the source table still has its original name.
 */
final class BackfillRoomNightClaims
{
    private const FALLBACK_NIGHTS = 7;

    public function run(): int
    {
        $inserted = 0;
        $table = 'cab'.'in_claims';

        DB::table($table)->orderBy('id')->chunkById(500, function ($claims) use (&$inserted): void {
            foreach ($claims as $claim) {
                $inserted += $this->insertClaim($claim);
            }
        });

        return $inserted;
    }

    private function insertClaim(stdClass $claim): int
    {
        $legacy = 'legacy_cab'.'in_claim_id';
        $already = DB::table('room_night_claims')
            ->where($legacy, $claim->id)
            ->exists();

        if ($already) {
            return 0;
        }

        $linkedId = $claim->departure_id ?? null;
        $row = is_numeric($linkedId)
            ? DB::table('departures')->where('id', (int) $linkedId)->first()
            : null;

        if (! $row instanceof stdClass) {
            return 0;
        }

        $stay = StayDates::forNights((string) $row->date, $this->nights($row));
        $group = (string) Str::uuid();
        $rows = [];

        foreach ($stay->eachNight() as $night) {
            $rows[] = [
                'room_id' => $claim->room_id,
                'night' => $night->toDateString(),
                'holder_type' => $claim->holder_type,
                'holder_id' => $claim->holder_id,
                'kind' => $claim->kind,
                'hold_type' => $claim->hold_type,
                'expires_at' => $claim->expires_at,
                'released_at' => $claim->released_at,
                'release_reason' => $claim->release_reason,
                'claim_group' => $group,
                $legacy => $claim->id,
                'created_at' => $claim->created_at,
                'updated_at' => $claim->updated_at,
                'created_by' => $claim->created_by,
                'updated_by' => $claim->updated_by,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        DB::table('room_night_claims')->insert($rows);

        return count($rows);
    }

    private function nights(stdClass $row): int
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
