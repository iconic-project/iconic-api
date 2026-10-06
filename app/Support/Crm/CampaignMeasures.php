<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Models\Booking;
use App\Models\Campaign;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class CampaignMeasures
{
    /**
     * @return array<int, array{redeemed: int, revenue: int, first_count: int, first_revenue: int, last_count: int, last_revenue: int, trade: int, roas: string|null}>
     */
    public static function byCampaign(): array
    {
        $charges = Booking::chargesTotalSql();
        $redeemed = self::redeemedWhere();
        $first = self::touchWhere('utm_first');
        $last = self::touchWhere('utm_last');

        $rows = DB::table('campaigns')
            ->leftJoin('offers', 'offers.id', '=', 'campaigns.offer_id')
            ->select('campaigns.id')
            ->selectRaw("(SELECT COUNT(*) FROM bookings WHERE {$redeemed}) as redeemed")
            ->selectRaw("(SELECT COALESCE(SUM({$charges}), 0) FROM bookings WHERE {$redeemed}) as revenue")
            ->selectRaw("(SELECT COUNT(*) FROM bookings WHERE {$first}) as first_count")
            ->selectRaw("(SELECT COALESCE(SUM({$charges}), 0) FROM bookings WHERE {$first}) as first_revenue")
            ->selectRaw("(SELECT COUNT(*) FROM bookings WHERE {$last}) as last_count")
            ->selectRaw("(SELECT COALESCE(SUM({$charges}), 0) FROM bookings WHERE {$last}) as last_revenue")
            ->selectRaw("(SELECT COUNT(*) FROM bookings WHERE {$redeemed} AND bookings.agency_id IS NOT NULL) as trade")
            ->selectRaw("CASE WHEN campaigns.media_spend = 0 THEN NULL ELSE ROUND((SELECT COALESCE(SUM({$charges}), 0) FROM bookings WHERE {$redeemed}) / campaigns.media_spend, 1) END as roas")
            ->get();

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(int) $row->id] = [
                'redeemed' => (int) $row->redeemed,
                'revenue' => (int) $row->revenue,
                'first_count' => (int) $row->first_count,
                'first_revenue' => (int) $row->first_revenue,
                'last_count' => (int) $row->last_count,
                'last_revenue' => (int) $row->last_revenue,
                'trade' => (int) $row->trade,
                'roas' => $row->roas === null ? null : (string) $row->roas,
            ];
        }

        return $keyed;
    }

    /**
     * @return LengthAwarePaginator<int, Booking>
     */
    public static function bookings(Campaign $campaign, int $perPage): LengthAwarePaginator
    {
        $campaign->loadMissing('offer');
        $charges = Booking::chargesTotalSql();
        $redeemed = self::bookingRedeemed($campaign);
        $first = self::bookingTouch($campaign, 'utm_first');
        $last = self::bookingTouch($campaign, 'utm_last');

        return Booking::query()
            ->whereRaw("({$redeemed} OR {$first} OR {$last})")
            ->select('bookings.*')
            ->selectRaw('bookings.check_in as departure_date')
            ->selectRaw("({$charges}) as charges_total")
            ->selectRaw("({$redeemed}) as counts_redeemed")
            ->selectRaw("({$first}) as counts_first")
            ->selectRaw("({$last}) as counts_last")
            ->orderByDesc('bookings.id')
            ->paginate($perPage);
    }

    private static function soldList(): string
    {
        return implode(', ', array_map(
            fn (string $status): string => "'".$status."'",
            ContactDerived::soldStatuses(),
        ));
    }

    private static function redeemedWhere(): string
    {
        return 'bookings.deleted_at IS NULL AND bookings.status IN ('.self::soldList().') AND campaigns.offer_id IS NOT NULL AND (LOWER(bookings.promo_code) = LOWER(offers.code) OR '.self::lineMatch().')';
    }

    private static function touchWhere(string $column): string
    {
        return 'bookings.deleted_at IS NULL AND bookings.status IN ('.self::soldList().") AND campaigns.utm_campaign IS NOT NULL AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(bookings.{$column}, '$.campaign'))) = campaigns.utm_campaign";
    }

    private static function lineMatch(): string
    {
        return <<<'SQL'
EXISTS (
    SELECT 1 FROM JSON_TABLE(IFNULL(bookings.price_lines, JSON_ARRAY()), '$[*]' COLUMNS (line_code VARCHAR(64) PATH '$.code')) AS line_rows
    WHERE LOWER(line_rows.line_code) = LOWER(offers.code)
)
SQL;
    }

    private static function bookingRedeemed(Campaign $campaign): string
    {
        $code = $campaign->offer?->code;

        if ($code === null || $code === '') {
            return '0';
        }

        $quoted = "'".str_replace("'", "''", strtolower($code))."'";

        return 'bookings.deleted_at IS NULL AND bookings.status IN ('.self::soldList().") AND (LOWER(bookings.promo_code) = {$quoted} OR EXISTS (
            SELECT 1 FROM JSON_TABLE(IFNULL(bookings.price_lines, JSON_ARRAY()), '$[*]' COLUMNS (line_code VARCHAR(64) PATH '$.code')) AS line_rows
            WHERE LOWER(line_rows.line_code) = {$quoted}
        ))";
    }

    private static function bookingTouch(Campaign $campaign, string $column): string
    {
        $key = $campaign->utm_campaign;

        if ($key === null || $key === '') {
            return '0';
        }

        $quoted = "'".str_replace("'", "''", $key)."'";

        return 'bookings.deleted_at IS NULL AND bookings.status IN ('.self::soldList().") AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(bookings.{$column}, '$.campaign'))) = {$quoted}";
    }
}
