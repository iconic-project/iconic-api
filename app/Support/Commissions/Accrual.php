<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\Enums\BookingStatus;
use App\Enums\CommissionAccrualStatus;
use App\Models\Booking;
use App\Support\BusinessTime;
use App\Support\Config\Documents\BusinessRulesDocument;
use Carbon\CarbonImmutable;

final class Accrual
{
    public static function payableDate(Booking $booking, BusinessRulesDocument $rules): CarbonImmutable
    {
        return $booking->stay()->checkOut()->addDays($rules->commission->payableDaysAfterCheckOut);
    }

    public static function status(Booking $booking, BusinessRulesDocument $rules): CommissionAccrualStatus
    {
        $booking->loadMissing('commissionPayout');

        if (in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::CancelledPostpaid], true)) {
            return CommissionAccrualStatus::Cancelled;
        }

        $cap = $rules->commission->capPct;

        if ($booking->commission_pct !== null
            && $booking->commission_pct > $cap
            && ! $booking->commission_approved
        ) {
            return CommissionAccrualStatus::Blocked;
        }

        if ($booking->commissionPayout !== null) {
            return CommissionAccrualStatus::Paid;
        }

        $today = BusinessTime::now()->toDateString();
        $payable = self::payableDate($booking, $rules)->toDateString();

        // TODO(OPEN: 19-05) Business rules do not say whether a collected no-show charge accrues commission.
        if ($booking->status === BookingStatus::CheckedOut && $payable <= $today) {
            return CommissionAccrualStatus::Payable;
        }

        return CommissionAccrualStatus::EarnedOnCompletion;
    }

    /**
     * Same precedence as status(). Bindings are cap percent, payable days, today (Y-m-d).
     * The query must left-join commission_payouts. Payable date is check-out plus the configured days.
     */
    public static function statusSql(string $payouts = 'commission_payouts'): string
    {
        $cancelled = "'".BookingStatus::Cancelled->value."', '".BookingStatus::CancelledPostpaid->value."'";
        $completed = BookingStatus::CheckedOut->value;

        return 'CASE
            WHEN bookings.status IN ('.$cancelled.') THEN \''.CommissionAccrualStatus::Cancelled->value.'\'
            WHEN bookings.commission_pct IS NOT NULL
                AND bookings.commission_pct > ?
                AND bookings.commission_approved = 0 THEN \''.CommissionAccrualStatus::Blocked->value.'\'
            WHEN '.$payouts.'.id IS NOT NULL THEN \''.CommissionAccrualStatus::Paid->value.'\'
            WHEN bookings.status = \''.$completed.'\'
                AND DATE_ADD(bookings.check_out, INTERVAL ? DAY) <= ? THEN \''.CommissionAccrualStatus::Payable->value.'\'
            ELSE \''.CommissionAccrualStatus::EarnedOnCompletion->value.'\'
        END';
    }
}
