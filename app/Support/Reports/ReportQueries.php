<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\AgencyStatus;
use App\Enums\BookingStatus;
use App\Enums\ChannelOfOrigin;
use App\Enums\CommissionAccrualStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Commissions\Accrual;
use App\Support\Crm\ContactDerived;
use App\Support\Metrics\CommercialMetrics;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Payments\PaymentsKpis;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tabular bodies for the ten report definitions. Cells are bookings, agencies, departures and money (O9).
 */
class ReportQueries
{
    public function __construct(
        private readonly CommercialMetrics $metrics,
        private readonly CurrentConfig $config,
    ) {}

    /**
     * @return array{headers: list<string>, rows: list<list<int|string>>}
     */
    public function table(string $key, MetricWindow $window, MetricScope $scope, ?User $actor): array
    {
        return match ($key) {
            'payments-received' => $this->paymentsReceived($window, $scope),
            'overdue' => $this->overdue($window, $scope),
            'forecast-30-day' => $this->forecast($window, $scope),
            'revenue-monthly' => $this->revenueMonthly($window, $scope),
            'commissions-payable' => $this->commissionsPayable($window, $scope),
            'gateway-reconciliation' => $this->gateway($window, $scope),
            'commercial-summary' => $this->commercialSummary($window, $scope, $actor),
            'occupancy' => $this->occupancy($window, $scope),
            'pipeline-summary' => $this->pipelineSummary($window, $scope, $actor),
            'agency-report' => $this->agencyReport($window, $scope),
            default => throw new InvalidArgumentException('Unknown report ['.$key.'].'),
        };
    }

    /**
     * @return array{headers: list<string>, rows: list<list<int|string>>}
     */
    private function paymentsReceived(MetricWindow $window, MetricScope $scope): array
    {
        $paid = PaymentStatus::paidValues();
        $kinds = [PaymentKind::Deposit->value, PaymentKind::Balance->value];
        [$scopeSql, $scopeBindings] = $this->bookingScope('bookings', $scope);
        $sql = 'SELECT DATE(payments.paid_at) AS paid_on,
                COALESCE(bookings.reference, bookings.request_reference, \'\') AS booking,
                payments.kind AS kind, payments.method AS method, payments.amount AS amount,
                payments.reference AS payment_reference
            FROM payments
            INNER JOIN bookings ON bookings.id = payments.booking_id
            WHERE payments.status IN ('.$this->placeholders($paid).')
              AND payments.kind IN ('.$this->placeholders($kinds).')
              AND DATE(payments.paid_at) >= ? AND DATE(payments.paid_at) <= ?
              AND bookings.deleted_at IS NULL'.$scopeSql.'
            ORDER BY paid_on, payments.id';

        return $this->grid(
            ['paid_on', 'booking', 'kind', 'method', 'amount', 'payment_reference'],
            DB::select($sql, [...$paid, ...$kinds, $window->from, $window->to, ...$scopeBindings]),
            ['paid_on', 'booking', 'kind', 'method', 'amount', 'payment_reference'],
        );
    }

    /**
     * @return array{headers: list<string>, rows: list<list<int|string>>}
     */
    private function overdue(MetricWindow $window, MetricScope $scope): array
    {
        [$cruise, $paid] = Booking::cruiseOutstandingSql();
        $due = Booking::dueDateSql();
        $statuses = [BookingStatus::Confirmed->value, BookingStatus::OnHoldAgency->value];
        [$scopeSql, $scopeBindings] = $this->bookingScope('bookings', $scope);
        $today = BusinessTime::now()->toDateString();
        $sql = 'SELECT COALESCE(bookings.reference, bookings.request_reference, \'\') AS booking,
                departures.`date` AS departure_date, ('.$due.') AS due_date, ('.$cruise.') AS cruise_outstanding
            FROM bookings
            INNER JOIN departures ON departures.id = bookings.departure_id
            WHERE bookings.deleted_at IS NULL
              AND bookings.status IN ('.$this->placeholders($statuses).')
              AND ('.$cruise.') > 0
              AND ? > ('.$due.')
              AND departures.`date` >= ? AND departures.`date` <= ?'.$scopeSql.'
            ORDER BY due_date, bookings.id';

        return $this->grid(
            ['booking', 'departure_date', 'due_date', 'cruise_outstanding'],
            DB::select($sql, [
                ...$paid,
                ...$statuses,
                ...$paid,
                $today,
                $window->from,
                $window->to,
                ...$scopeBindings,
            ]),
            ['booking', 'departure_date', 'due_date', 'cruise_outstanding'],
        );
    }

    /**
     * @return array{headers: list<string>, rows: list<list<int|string>>}
     */
    private function forecast(MetricWindow $window, MetricScope $scope): array
    {
        [$cruise, $paid] = Booking::cruiseOutstandingSql();
        $due = Booking::dueDateSql();
        $statuses = [
            BookingStatus::PendingPayment->value,
            BookingStatus::Confirmed->value,
            BookingStatus::OnHoldAgency->value,
        ];
        [$scopeSql, $scopeBindings] = $this->bookingScope('bookings', $scope);
        $sql = 'SELECT COALESCE(bookings.reference, bookings.request_reference, \'\') AS booking,
                ('.$due.') AS due_date, ('.$cruise.') AS cruise_outstanding
            FROM bookings
            INNER JOIN departures ON departures.id = bookings.departure_id
            WHERE bookings.deleted_at IS NULL
              AND bookings.status IN ('.$this->placeholders($statuses).')
              AND ('.$cruise.') > 0
              AND ('.$due.') >= ? AND ('.$due.') <= ?'.$scopeSql.'
            ORDER BY due_date, bookings.id';

        return $this->grid(
            ['booking', 'due_date', 'cruise_outstanding'],
            DB::select($sql, [
                ...$paid,
                ...$statuses,
                ...$paid,
                $window->from,
                $window->to,
                ...$scopeBindings,
            ]),
            ['booking', 'due_date', 'cruise_outstanding'],
        );
    }

    /**
     * @return array{headers: list<string>, rows: list<list<int|string>>}
     */
    private function revenueMonthly(MetricWindow $window, MetricScope $scope): array
    {
        $statuses = ContactDerived::soldStatuses();
        [$scopeSql, $scopeBindings] = $this->bookingScope('bookings', $scope);
        $sql = 'SELECT DATE_FORMAT(departures.`date`, \'%Y-%m\') AS month,
                COUNT(*) AS bookings, COALESCE(SUM(bookings.total), 0) AS cruise_revenue
            FROM bookings
            INNER JOIN departures ON departures.id = bookings.departure_id
            WHERE bookings.deleted_at IS NULL
              AND bookings.status IN ('.$this->placeholders($statuses).')
              AND departures.`date` >= ? AND departures.`date` <= ?'.$scopeSql.'
            GROUP BY month
            ORDER BY month';

        return $this->grid(
            ['month', 'bookings', 'cruise_revenue'],
            DB::select($sql, [...$statuses, $window->from, $window->to, ...$scopeBindings]),
            ['month', 'bookings', 'cruise_revenue'],
        );
    }

    /**
     * @return array{headers: list<string>, rows: list<list<int|string>>}
     */
    private function commissionsPayable(MetricWindow $window, MetricScope $scope): array
    {
        $inner = $this->accrualFrom($window, $scope);
        $sql = 'SELECT agency_reference, agency_name, booking, amount, departure_date
            FROM ('.$inner['sql'].') accruals
            WHERE accrual = ?
            ORDER BY agency_reference, booking';
        $bindings = [...$inner['bindings'], CommissionAccrualStatus::Payable->value];

        return $this->grid(
            ['agency_reference', 'agency_name', 'booking', 'amount', 'departure_date'],
            DB::select($sql, $bindings),
            ['agency_reference', 'agency_name', 'booking', 'amount', 'departure_date'],
        );
    }

    /**
     * @return array{headers: list<string>, rows: list<list<int|string>>}
     */
    private function gateway(MetricWindow $window, MetricScope $scope): array
    {
        [$scopeSql, $scopeBindings] = $this->bookingScope('bookings', $scope);
        $sql = 'SELECT payments.gateway_id AS gateway_id, payments.amount AS amount, payments.status AS status,
                COALESCE(bookings.reference, bookings.request_reference, \'\') AS booking,
                DATE(payments.paid_at) AS paid_on
            FROM payments
            INNER JOIN bookings ON bookings.id = payments.booking_id
            WHERE payments.gateway_id IS NOT NULL AND payments.gateway_id <> \'\'
              AND DATE(payments.paid_at) >= ? AND DATE(payments.paid_at) <= ?
              AND bookings.deleted_at IS NULL'.$scopeSql.'
            ORDER BY paid_on, payments.id';

        return $this->grid(
            ['gateway_id', 'amount', 'status', 'booking', 'paid_on'],
            DB::select($sql, [$window->from, $window->to, ...$scopeBindings]),
            ['gateway_id', 'amount', 'status', 'booking', 'paid_on'],
        );
    }

    /**
     * @return array{headers: list<string>, rows: list<list<int|string>>}
     */
    private function commercialSummary(MetricWindow $window, MetricScope $scope, ?User $actor): array
    {
        $occupancy = $this->metrics->occupancy($window, $scope);
        $revpab = $this->metrics->revpab($window, $scope);
        $adr = $this->metrics->adr($window, $scope);
        $lead = $this->metrics->leadTime($window, $scope);
        $nps = $this->metrics->nps($window, $scope);
        $commissions = $this->metrics->commissions($window, $scope);
        $cash = $actor instanceof User
            ? $this->metrics->cash($window, $scope, $actor)
            : $this->cashAcross($window, $scope);

        $rows = [
            ['occupancy', (string) ($occupancy['occupancy'] ?? '')],
            ['sold_berths', $occupancy['sold_berths']],
            ['sellable_berths', $occupancy['sellable_berths']],
            ['revpab', (string) ($revpab['revpab'] ?? '')],
            ['adr', (string) ($adr['adr'] ?? '')],
            ['cruise_revenue', $revpab['cruise_revenue']],
            ['lead_time_average_days', (string) ($lead['average_days'] ?? '')],
            ['lead_time_median_days', (string) ($lead['median_days'] ?? '')],
            ['nps_average', (string) ($nps['average_score'] ?? '')],
            ['nps_promoters', $nps['promoters']],
            ['nps_passives', $nps['passives']],
            ['nps_detractors', $nps['detractors']],
            ['commission_blocked', $commissions['blocked']],
            ['commission_earned', $commissions['earned']],
            ['commission_payable', $commissions['payable']],
            ['commission_paid', $commissions['paid']],
            ['cash_collected', $cash['collected']],
            ['cash_pending', $cash['pending']],
            ['cash_overdue', $cash['overdue']],
        ];

        foreach ($this->metrics->channelMix($window, $scope)['rows'] as $channel) {
            $rows[] = ['channel '.$channel['channel'], $channel['revenue']];
        }

        foreach ($this->metrics->nationalityMix($window, $scope)['rows'] as $country) {
            $rows[] = ['guests '.$country['country_code'], $country['guests']];
        }

        return ['headers' => ['figure', 'value'], 'rows' => $rows];
    }

    /**
     * @return array{headers: list<string>, rows: list<list<int|string>>}
     */
    private function occupancy(MetricWindow $window, MetricScope $scope): array
    {
        $rows = [];

        foreach ($this->metrics->occupancy($window, $scope)['departures'] as $departure) {
            $rows[] = [
                $departure['date'],
                $departure['property_code'],
                $departure['sold_berths'],
                $departure['sellable_berths'],
                (string) ($departure['occupancy'] ?? ''),
            ];
        }

        return [
            'headers' => ['departure_date', 'property', 'sold_berths', 'sellable_berths', 'occupancy'],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{headers: list<string>, rows: list<list<int|string>>}
     */
    private function pipelineSummary(MetricWindow $window, MetricScope $scope, ?User $actor): array
    {
        $cash = $actor instanceof User
            ? PaymentsKpis::for($actor, $window->from, $window->to, $scope)
            : PaymentsKpis::across($window->from, $window->to, $scope);

        return [
            'headers' => ['figure', 'value'],
            'rows' => [
                ['collected', $cash['collected']],
                ['pending', $cash['pending']],
                ['overdue', $cash['overdue_amount']],
                ['scheduled', PaymentsKpis::scheduledIn()],
            ],
        ];
    }

    /**
     * @return array{headers: list<string>, rows: list<list<int|string>>}
     */
    private function agencyReport(MetricWindow $window, MetricScope $scope): array
    {
        $inner = $this->accrualFrom($window, $scope);
        $blocked = CommissionAccrualStatus::Blocked->value;
        $earned = CommissionAccrualStatus::EarnedOnCompletion->value;
        $payable = CommissionAccrualStatus::Payable->value;
        $sql = 'SELECT agency_reference, agency_name,
                COALESCE(SUM(CASE WHEN accrual = \''.$blocked.'\' THEN amount ELSE 0 END), 0) AS blocked,
                COALESCE(SUM(CASE WHEN accrual = \''.$earned.'\' THEN amount ELSE 0 END), 0) AS earned,
                COALESCE(SUM(CASE WHEN accrual = \''.$payable.'\' THEN amount ELSE 0 END), 0) AS payable,
                COALESCE(SUM(paid_amount), 0) AS paid
            FROM ('.$inner['sql'].') accruals
            GROUP BY agency_reference, agency_name
            ORDER BY agency_reference';

        return $this->grid(
            ['agency_reference', 'agency_name', 'blocked', 'earned', 'payable', 'paid'],
            DB::select($sql, $inner['bindings']),
            ['agency_reference', 'agency_name', 'blocked', 'earned', 'payable', 'paid'],
        );
    }

    /**
     * @return array{collected: int, pending: int, overdue: int}
     */
    private function cashAcross(MetricWindow $window, MetricScope $scope): array
    {
        $cash = PaymentsKpis::across($window->from, $window->to, $scope);

        return [
            'collected' => $cash['collected'],
            'pending' => $cash['pending'],
            'overdue' => $cash['overdue_amount'],
        ];
    }

    /**
     * @return array{sql: string, bindings: list<int|string>}
     */
    private function accrualFrom(MetricWindow $window, MetricScope $scope): array
    {
        $rules = $this->config->businessRules();
        $status = Accrual::statusSql();
        [$scopeSql, $scopeBindings] = $this->bookingScope('bookings', $scope);
        $sql = 'SELECT agencies.reference AS agency_reference, agencies.name AS agency_name,
                COALESCE(bookings.reference, bookings.request_reference, \'\') AS booking,
                CASE WHEN bookings.commission_pct IS NOT NULL THEN ROUND(bookings.total * bookings.commission_pct / 100) ELSE 0 END AS amount,
                departures.`date` AS departure_date,
                commission_payouts.amount AS paid_amount,
                ('.$status.') AS accrual
            FROM bookings
            INNER JOIN agencies ON agencies.id = bookings.agency_id
            INNER JOIN departures ON departures.id = bookings.departure_id
            INNER JOIN itineraries ON itineraries.id = departures.itinerary_id
            LEFT JOIN commission_payouts ON commission_payouts.booking_id = bookings.id
            WHERE agencies.status = ?
              AND departures.`date` >= ? AND departures.`date` <= ?'.$scopeSql;

        return [
            'sql' => $sql,
            'bindings' => [
                $rules->commission->capPct,
                $rules->commission->payableDaysAfterCheckOut,
                BusinessTime::now()->toDateString(),
                AgencyStatus::Approved->value,
                $window->from,
                $window->to,
                ...$scopeBindings,
            ],
        ];
    }

    /**
     * @return array{0: string, 1: list<int|string>}
     */
    private function bookingScope(string $alias, MetricScope $scope): array
    {
        $sql = '';
        $bindings = [];

        if ($scope->propertyId !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM departures scope_departures WHERE scope_departures.id = '.$alias.'.departure_id AND scope_departures.property_id = ?)';
            $bindings[] = $scope->propertyId;
        }

        if ($scope->itineraryId !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM departures scope_itineraries WHERE scope_itineraries.id = '.$alias.'.departure_id AND scope_itineraries.itinerary_id = ?)';
            $bindings[] = $scope->itineraryId;
        }

        if ($scope->agencyId !== null) {
            $sql .= ' AND '.$alias.'.agency_id = ?';
            $bindings[] = $scope->agencyId;
        }

        if ($scope->channel !== null) {
            $values = ChannelOfOrigin::valuesInGroup($scope->channel);
            $sql .= ' AND '.$alias.'.channel_of_origin IN ('.$this->placeholders($values).')';
            array_push($bindings, ...$values);
        }

        return [$sql, $bindings];
    }

    /**
     * @param  list<int|string>  $values
     */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * @param  list<string>  $headers
     * @param  list<object>  $records
     * @param  list<string>  $columns
     * @return array{headers: list<string>, rows: list<list<int|string>>}
     */
    private function grid(array $headers, array $records, array $columns): array
    {
        $rows = [];

        foreach ($records as $record) {
            $cells = [];

            foreach ($columns as $column) {
                $value = $record->{$column} ?? '';
                $cells[] = is_int($value) ? $value : (string) $value;
            }

            $rows[] = $cells;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }
}
