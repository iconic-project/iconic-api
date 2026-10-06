<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Enums\BookingStatus;
use App\Enums\ChannelOfOrigin;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Config\Documents\Rates\RatePlan;
use App\Support\Metrics\MetricScope;
use Illuminate\Database\Eloquent\Builder;
use stdClass;

final class PaymentsKpis
{
    /**
     * Statuses that still owe. Matches the prototype's `pend` (balance > 0,
     * not REQUESTED) and includes overdue CONFIRMED / ON_HOLD_AGENCY rows.
     *
     * @return list<string>
     */
    public static function owingStatuses(): array
    {
        return [
            BookingStatus::PendingPayment->value,
            BookingStatus::Confirmed->value,
            BookingStatus::OnHoldAgency->value,
        ];
    }

    /**
     * @return array{collected: int, pending: int, overdue_amount: int}
     */
    public static function ledger(): array
    {
        $row = self::aggregate(true, null, null, null);

        return [
            'collected' => (int) ($row->collected ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'overdue_amount' => (int) ($row->overdue_amount ?? 0),
        ];
    }

    public static function scheduledIn(): int
    {
        [$balanceSql, $paid] = Booking::balanceSql();
        $statuses = [
            BookingStatus::Confirmed->value,
            BookingStatus::FullyPaid->value,
        ];

        $value = Booking::query()
            ->whereIn('status', $statuses)
            ->selectRaw('COALESCE(SUM(GREATEST(('.$balanceSql.'), 0)), 0) as scheduled', $paid)
            ->value('scheduled');

        return (int) $value;
    }

    /**
     * @return array{
     *     collected: int,
     *     deposits: int,
     *     pending: int,
     *     pending_count: int,
     *     overdue_count: int,
     *     overdue_amount: int,
     *     commission_accrued: int,
     *     deposit_pct: int,
     *     balance_days: int,
     *     commission_payable_days: int,
     *     commission_cap_pct: int,
     *     wire_window_hours: int
     * }
     */
    public static function for(User $actor, ?string $from, ?string $to, ?MetricScope $scope = null): array
    {
        $config = app(CurrentConfig::class);
        $plan = self::defaultPlan();
        $viewAll = $actor->hasPermission(Permission::BookingsViewAll);
        $row = self::aggregate($viewAll, $viewAll ? null : $actor->id, $from, $to, $scope);

        return [
            'collected' => (int) ($row->collected ?? 0),
            'deposits' => (int) ($row->deposits ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'pending_count' => (int) ($row->pending_count ?? 0),
            'overdue_count' => (int) ($row->overdue_count ?? 0),
            'overdue_amount' => (int) ($row->overdue_amount ?? 0),
            'commission_accrued' => (int) ($row->commission_accrued ?? 0),
            'deposit_pct' => $plan->depositPct,
            'balance_days' => $plan->balanceDays,
            'commission_payable_days' => $config->businessRules()->commission->payableDaysAfterCheckOut,
            'commission_cap_pct' => $config->businessRules()->commission->capPct,
            'wire_window_hours' => $config->businessRules()->payments->wireWindowHours,
        ];
    }

    /**
     * The same figures as for(), without an own-records cut. Scheduled reports use this.
     *
     * @return array{
     *     collected: int,
     *     deposits: int,
     *     pending: int,
     *     pending_count: int,
     *     overdue_count: int,
     *     overdue_amount: int,
     *     commission_accrued: int,
     *     deposit_pct: int,
     *     balance_days: int,
     *     commission_payable_days: int,
     *     commission_cap_pct: int,
     *     wire_window_hours: int
     * }
     */
    public static function across(?string $from, ?string $to, ?MetricScope $scope = null): array
    {
        $config = app(CurrentConfig::class);
        $plan = self::defaultPlan();
        $row = self::aggregate(true, null, $from, $to, $scope);

        return [
            'collected' => (int) ($row->collected ?? 0),
            'deposits' => (int) ($row->deposits ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'pending_count' => (int) ($row->pending_count ?? 0),
            'overdue_count' => (int) ($row->overdue_count ?? 0),
            'overdue_amount' => (int) ($row->overdue_amount ?? 0),
            'commission_accrued' => (int) ($row->commission_accrued ?? 0),
            'deposit_pct' => $plan->depositPct,
            'balance_days' => $plan->balanceDays,
            'commission_payable_days' => $config->businessRules()->commission->payableDaysAfterCheckOut,
            'commission_cap_pct' => $config->businessRules()->commission->capPct,
            'wire_window_hours' => $config->businessRules()->payments->wireWindowHours,
        ];
    }

    private static function aggregate(bool $viewAll, ?int $ownerId, ?string $from, ?string $to, ?MetricScope $scope = null): stdClass
    {
        [$balanceSql, $paid] = Booking::balanceSql();
        [$staySql, $stayPaid] = Booking::stayOutstandingSql();
        $owing = self::owingStatuses();
        $overdueStatuses = [
            BookingStatus::Confirmed->value,
            BookingStatus::OnHoldAgency->value,
        ];
        $cancelled = [
            BookingStatus::Cancelled->value,
            BookingStatus::CancelledPostpaid->value,
        ];
        $today = BusinessTime::now()->toDateString();
        $dueSql = Booking::overdueDateSql();

        $owingIn = implode(', ', array_fill(0, count($owing), '?'));
        $overdueIn = implode(', ', array_fill(0, count($overdueStatuses), '?'));
        $cancelledIn = implode(', ', array_fill(0, count($cancelled), '?'));

        $pendingWhen = 'bookings.status IN ('.$owingIn.') AND ('.$balanceSql.') > 0';
        $overdueWhen = 'bookings.status IN ('.$overdueIn.') AND ('.$staySql.') > 0 AND '.$dueSql;

        $collected = self::paidSumQuery($viewAll, $ownerId, $from, $to, depositsOnly: false, scope: $scope);
        $deposits = self::paidSumQuery($viewAll, $ownerId, $from, $to, depositsOnly: true, scope: $scope);

        $row = self::visibleBookings($viewAll, $ownerId, $from, $to, $scope)
            ->toBase()
            ->selectRaw(
                '('.$collected->toSql().') as collected, '.
                '('.$deposits->toSql().') as deposits, '.
                'COALESCE(SUM(CASE WHEN '.$pendingWhen.' THEN ('.$balanceSql.') ELSE 0 END), 0) as pending, '.
                'COALESCE(SUM(CASE WHEN '.$pendingWhen.' THEN 1 ELSE 0 END), 0) as pending_count, '.
                'COALESCE(SUM(CASE WHEN '.$overdueWhen.' THEN 1 ELSE 0 END), 0) as overdue_count, '.
                'COALESCE(SUM(CASE WHEN '.$overdueWhen.' THEN ('.$staySql.') ELSE 0 END), 0) as overdue_amount, '.
                'COALESCE(SUM(CASE WHEN bookings.commission_approved = 1 AND bookings.commission_pct IS NOT NULL '.
                'AND bookings.status NOT IN ('.$cancelledIn.') '.
                'THEN ROUND(bookings.total * bookings.commission_pct / 100) ELSE 0 END), 0) as commission_accrued',
                [
                    ...$collected->getBindings(),
                    ...$deposits->getBindings(),
                    ...$owing,
                    ...$paid,
                    ...$paid,
                    ...$owing,
                    ...$paid,
                    ...$overdueStatuses,
                    ...$stayPaid,
                    $today,
                    $today,
                    ...$overdueStatuses,
                    ...$stayPaid,
                    $today,
                    $today,
                    ...$stayPaid,
                    ...$cancelled,
                ],
            )
            ->first();

        return $row instanceof stdClass ? $row : (object) [];
    }

    /**
     * @return Builder<Booking>
     */
    private static function visibleBookings(bool $viewAll, ?int $ownerId, ?string $from, ?string $to, ?MetricScope $scope = null): Builder
    {
        $query = Booking::query()
            ->when(
                ! $viewAll && $ownerId !== null,
                fn (Builder $query) => $query->where('bookings.owner_id', $ownerId),
            )
            ->when(
                $from !== null,
                fn (Builder $query) => $query->whereDate('bookings.check_in', '>=', $from),
            )
            ->when(
                $to !== null,
                fn (Builder $query) => $query->whereDate('bookings.check_in', '<=', $to),
            );

        self::constrainScope($query, $scope);

        return $query;
    }

    /**
     * @return Builder<Payment>
     */
    private static function paidSumQuery(
        bool $viewAll,
        ?int $ownerId,
        ?string $from,
        ?string $to,
        bool $depositsOnly,
        ?MetricScope $scope = null,
    ): Builder {
        return Payment::query()
            ->whereHas(
                'booking',
                function (Builder $booking) use ($viewAll, $ownerId, $scope): void {
                    if (! $viewAll && $ownerId !== null) {
                        $booking->where('owner_id', $ownerId);
                    }

                    self::constrainScope($booking, $scope);
                },
            )
            ->whereIn('payments.status', PaymentStatus::paidValues())
            ->when(
                $depositsOnly,
                fn (Builder $query) => $query->where('payments.kind', PaymentKind::Deposit),
                fn (Builder $query) => $query->whereIn('payments.kind', [
                    PaymentKind::Deposit,
                    PaymentKind::Balance,
                ]),
            )
            ->when(
                $from !== null,
                fn (Builder $query) => $query->whereDate('payments.paid_at', '>=', $from),
            )
            ->when(
                $to !== null,
                fn (Builder $query) => $query->whereDate('payments.paid_at', '<=', $to),
            )
            ->selectRaw('COALESCE(SUM(payments.amount), 0)');
    }

    /**
     * @param  Builder<Booking>  $booking
     */
    private static function constrainScope(Builder $booking, ?MetricScope $scope): void
    {
        if (! $scope instanceof MetricScope) {
            return;
        }

        $propertyId = $scope->propertyId;

        if ($propertyId !== null) {
            $booking->where('property_id', $propertyId);
        }

        if ($scope->agencyId !== null) {
            $booking->where('agency_id', $scope->agencyId);
        }

        $channel = $scope->channel;

        if ($channel !== null) {
            $booking->whereIn('channel_of_origin', ChannelOfOrigin::valuesInGroup($channel));
        }
    }

    private static function defaultPlan(): RatePlan
    {
        $plans = app(CurrentConfig::class)->rates()->ratePlans;

        foreach ($plans as $plan) {
            if ($plan->isDefault) {
                return $plan;
            }
        }

        $first = $plans[0] ?? null;

        if (! $first instanceof RatePlan) {
            throw new \RuntimeException('No rate plan is published.');
        }

        return $first;
    }
}
