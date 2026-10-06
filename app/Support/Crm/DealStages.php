<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Enums\BookingStatus;
use App\Models\Booking;

final class DealStages
{
    public static function boundMatchSql(): string
    {
        return '((deals.booking_id IS NOT NULL AND bookings.id = deals.booking_id)'
            .' OR (deals.group_id IS NOT NULL AND bookings.group_id = deals.group_id))'
            .' AND bookings.deleted_at IS NULL';
    }

    public static function stageSql(): string
    {
        $match = self::boundMatchSql();
        $lost = self::in(BookingStatus::Cancelled, BookingStatus::CancelledPostpaid, BookingStatus::Released);
        $won = self::in(BookingStatus::CheckedOut);
        $confirmed = self::in(
            BookingStatus::Confirmed,
            BookingStatus::FullyPaid,
            BookingStatus::InHouse,
            BookingStatus::Overdue,
        );
        $deposit = self::in(
            BookingStatus::Requested,
            BookingStatus::PendingPayment,
            BookingStatus::OnHoldAgency,
        );

        return "CASE
            WHEN deals.booking_id IS NULL AND deals.group_id IS NULL AND deals.stage = 'LOST' THEN 'LOST'
            WHEN deals.booking_id IS NOT NULL OR deals.group_id IS NOT NULL THEN
                CASE
                    WHEN (SELECT COUNT(*) FROM bookings WHERE {$match}) > 0
                        AND (SELECT COUNT(*) FROM bookings WHERE {$match} AND bookings.status NOT IN ({$lost})) = 0
                        THEN 'LOST'
                    WHEN EXISTS (SELECT 1 FROM bookings WHERE {$match} AND bookings.status IN ({$won}))
                        THEN 'WON_COMPLETED'
                    WHEN EXISTS (SELECT 1 FROM bookings WHERE {$match} AND bookings.status IN ({$confirmed}))
                        THEN 'BOOKING_CONFIRMED'
                    WHEN EXISTS (SELECT 1 FROM bookings WHERE {$match} AND bookings.status IN ({$deposit}))
                        THEN 'DEPOSIT_PENDING'
                    ELSE deals.stage
                END
            ELSE deals.stage
        END";
    }

    public static function valueSql(): string
    {
        $match = self::boundMatchSql();
        $zero = self::in(BookingStatus::Cancelled, BookingStatus::CancelledPostpaid, BookingStatus::Released);
        $charges = Booking::chargesTotalSql();

        return "CASE
            WHEN deals.booking_id IS NULL AND deals.group_id IS NULL THEN deals.estimate
            ELSE (
                SELECT COALESCE(SUM(CASE WHEN bookings.status IN ({$zero}) THEN 0 ELSE ({$charges}) END), 0)
                FROM bookings
                WHERE {$match}
            )
        END";
    }

    public static function valueLabelSql(): string
    {
        return "CASE
            WHEN deals.booking_id IS NOT NULL OR deals.group_id IS NOT NULL THEN 'FROM RMS'
            ELSE 'CRM ESTIMATE'
        END";
    }

    public static function bookingColumnSql(string $expression): string
    {
        $match = self::boundMatchSql();
        $order = "'CHECKED_OUT','NO_SHOW','FULLY_PAID','IN_HOUSE','OVERDUE','CONFIRMED','PENDING_PAYMENT','ON_HOLD_AGENCY','REQUESTED','WAITLISTED','CANCELLED','CANCELLED_POSTPAID','RELEASED'";

        return "(
            SELECT {$expression}
            FROM bookings
            WHERE {$match}
            ORDER BY FIELD(bookings.status, {$order}), bookings.id
            LIMIT 1
        )";
    }

    private static function in(BookingStatus ...$statuses): string
    {
        return implode(', ', array_map(
            fn (BookingStatus $status): string => "'".$status->value."'",
            $statuses,
        ));
    }
}
