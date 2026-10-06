<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Actions\Restrictions\SetStayRestrictions;
use App\Support\Stays\StayDates;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * One-time copy of CLOSED and HIDDEN inventory rows onto property-wide stop_sell.
 * Later status changes are not synced. ON_SALE and CHARTER are left alone.
 * The occupied nights are [check-in, check-out). The restriction range is inclusive,
 * so `to` is the last night, not the check-out date.
 */
final class BackfillClosedDepartureRestrictions
{
    private const FALLBACK_NIGHTS = 7;

    public function __construct(
        private readonly SetStayRestrictions $set,
    ) {}

    public static function run(): int
    {
        return app(self::class)->backfill();
    }

    public function backfill(): int
    {
        $count = 0;

        DB::table('departures')
            ->whereIn('status', ['CLOSED', 'HIDDEN'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    $this->copy($row);
                    $count++;
                }
            });

        return $count;
    }

    private function copy(stdClass $row): void
    {
        $stay = StayDates::forNights((string) $row->date, $this->nights($row));

        $this->set->handle([
            'property_id' => (int) $row->property_id,
            'room_type_ids' => [],
            'from' => $stay->checkIn()->toDateString(),
            'to' => $stay->lastNight()->toDateString(),
            'stop_sell' => true,
        ]);
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
