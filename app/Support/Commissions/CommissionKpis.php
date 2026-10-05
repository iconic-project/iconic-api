<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\Enums\AgencyStatus;
use App\Enums\CommissionAccrualStatus;
use App\Support\BusinessTime;
use App\Support\Config\Documents\BusinessRulesDocument;
use Illuminate\Support\Facades\DB;

final class CommissionKpis
{
    /**
     * Approved agencies only. Check-in dates fall inside from/to when those are set.
     *
     * @return array{commission_accrued: int, commission_payable: int, commission_paid: int}
     */
    public static function forApproved(?string $from, ?string $to, BusinessRulesDocument $rules): array
    {
        $status = Accrual::statusSql();
        $amount = 'ROUND(bookings.total * bookings.commission_pct / 100)';

        $query = DB::table('bookings')
            ->join('agencies', 'agencies.id', '=', 'bookings.agency_id')
            ->leftJoin('commission_payouts', 'commission_payouts.booking_id', '=', 'bookings.id')
            ->where('agencies.status', AgencyStatus::Approved->value)
            ->when($from !== null, fn ($inner) => $inner->whereDate('bookings.check_in', '>=', $from))
            ->when($to !== null, fn ($inner) => $inner->whereDate('bookings.check_in', '<=', $to));

        /** @var object{commission_accrued: int|string|null, commission_payable: int|string|null, commission_paid: int|string|null}|null $row */
        $row = $query->selectRaw(
            'COALESCE(SUM(CASE WHEN bookings.commission_approved = 1 AND bookings.commission_pct IS NOT NULL AND commission_payouts.id IS NULL THEN '.$amount.' ELSE 0 END), 0) as commission_accrued, '.
            'COALESCE(SUM(CASE WHEN ('.$status.') = ? THEN '.$amount.' ELSE 0 END), 0) as commission_payable, '.
            'COALESCE(SUM(commission_payouts.amount), 0) as commission_paid',
            [
                $rules->commission->capPct,
                $rules->commission->payableDaysAfterCheckOut,
                BusinessTime::now()->toDateString(),
                CommissionAccrualStatus::Payable->value,
            ],
        )->first();

        if (! is_object($row)) {
            return [
                'commission_accrued' => 0,
                'commission_payable' => 0,
                'commission_paid' => 0,
            ];
        }

        return [
            'commission_accrued' => (int) ($row->commission_accrued ?? 0),
            'commission_payable' => (int) ($row->commission_payable ?? 0),
            'commission_paid' => (int) ($row->commission_paid ?? 0),
        ];
    }
}
