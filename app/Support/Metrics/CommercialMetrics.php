<?php

declare(strict_types=1);

namespace App\Support\Metrics;

use App\Enums\AgencyStatus;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ChannelOfOrigin;
use App\Enums\ClaimKind;
use App\Enums\CommissionAccrualStatus;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessTime;
use App\Support\Commissions\Accrual;
use App\Support\Operations\CommissionScan;
use App\Support\Payments\PaymentsKpis;
use App\Support\Rounding;
use Illuminate\Support\Facades\DB;

/**
 * Every commercial figure, computed in SQL and never stored (O1).
 * Cruise revenue is bookings.total — the cabin charge — and never extras or fees (I9).
 */
final class CommercialMetrics
{
    public function __construct(private readonly CurrentConfig $config) {}

    /**
     * @return array{
     *     window: array{from: string, to: string},
     *     scope: array{property: int|null, itinerary: int|null, channel: string|null, agency: int|null},
     *     metrics: array{
     *         occupancy: array{sold_berths: int, sellable_berths: int, occupancy: string|null, departures: list<array{id: int, date: string, property_code: string, sold_berths: int, sellable_berths: int, occupancy: string|null}>, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         revpab: array{cruise_revenue: int, sellable_berths: int, revpab: int|null, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         adr: array{cruise_revenue: int, berths_sold: int, adr: int|null, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         lead_time: array{average_days: string|null, median_days: string|null, bookings: int, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         channel_mix: array{rows: list<array{channel: string, group: string, bookings: int, revenue: int}>, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         nationality_mix: array{rows: list<array{country_code: string, guests: int}>, unknown: int, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         nps: array{average_score: string|null, responses: int, promoters: int, passives: int, detractors: int, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         commissions: array{blocked: int, earned: int, payable: int, paid: int, definition: array{sentence: string, filters_on: string, excludes: string}},
     *         cash: array{collected: int, pending: int, overdue: int, deposit_share_pct: int|null, definition: array{sentence: string, filters_on: string, excludes: string}}
     *     }
     * }
     */
    public function present(MetricWindow $window, MetricScope $scope, User $actor): array
    {
        return [
            'window' => $window->toArray(),
            'scope' => $scope->toArray(),
            'metrics' => [
                'occupancy' => [
                    ...$this->occupancy($window, $scope),
                    'definition' => MetricCatalogue::get('occupancy'),
                ],
                'revpab' => [
                    ...$this->revpab($window, $scope),
                    'definition' => MetricCatalogue::get('revpab'),
                ],
                'adr' => [
                    ...$this->adr($window, $scope),
                    'definition' => MetricCatalogue::get('adr'),
                ],
                'lead_time' => [
                    ...$this->leadTime($window, $scope),
                    'definition' => MetricCatalogue::get('lead_time'),
                ],
                'channel_mix' => [
                    ...$this->channelMix($window, $scope),
                    'definition' => MetricCatalogue::get('channel_mix'),
                ],
                'nationality_mix' => [
                    ...$this->nationalityMix($window, $scope),
                    'definition' => MetricCatalogue::get('nationality_mix'),
                ],
                'nps' => [
                    ...$this->nps($window, $scope),
                    'definition' => MetricCatalogue::get('nps'),
                ],
                'commissions' => [
                    ...$this->commissions($window, $scope),
                    'definition' => MetricCatalogue::get('commissions'),
                ],
                'cash' => [
                    ...$this->cash($window, $scope, $actor),
                    'definition' => MetricCatalogue::get('cash'),
                ],
            ],
        ];
    }

    /**
     * Filters on the departure date. Sold berths follow Availability: an active booking claim.
     * A charter (a booking claim status, not a request hold) counts as every cabin on the property.
     * Sellable berths are cabins that are not blocked.
     *
     * @return array{
     *     sold_berths: int,
     *     sellable_berths: int,
     *     occupancy: string|null,
     *     departures: list<array{id: int, date: string, property_code: string, sold_berths: int, sellable_berths: int, occupancy: string|null}>
     * }
     */
    public function occupancy(MetricWindow $window, MetricScope $scope): array
    {
        [$sql, $bindings] = $this->departureBerthsSql($window, $scope);
        $rows = DB::select($sql.' ORDER BY departure_date, id', $bindings);

        $departures = [];
        $sold = 0;
        $sellable = 0;

        foreach ($rows as $row) {
            $entry = $this->berthRow($row);
            $sold += $entry['sold_berths'];
            $sellable += $entry['sellable_berths'];
            $departures[] = $entry;
        }

        return [
            'sold_berths' => $sold,
            'sellable_berths' => $sellable,
            'occupancy' => $this->ratio($sold, $sellable),
            'departures' => $departures,
        ];
    }

    /**
     * Filters on the departure date. Cruise revenue excludes extras and fees.
     *
     * @return array{cruise_revenue: int, sellable_berths: int, revpab: int|null}
     */
    public function revpab(MetricWindow $window, MetricScope $scope): array
    {
        $totals = $this->revenueAndBerths($window, $scope);

        return [
            'cruise_revenue' => $totals['revenue'],
            'sellable_berths' => $totals['sellable'],
            'revpab' => $totals['sellable'] === 0 ? null : Rounding::halfUp($totals['revenue'] / $totals['sellable']),
        ];
    }

    /**
     * Filters on the departure date. Cruise revenue excludes extras and fees.
     *
     * @return array{cruise_revenue: int, berths_sold: int, adr: int|null}
     */
    public function adr(MetricWindow $window, MetricScope $scope): array
    {
        $totals = $this->revenueAndBerths($window, $scope);

        return [
            'cruise_revenue' => $totals['revenue'],
            'berths_sold' => $totals['sold'],
            'adr' => $totals['sold'] === 0 ? null : Rounding::halfUp($totals['revenue'] / $totals['sold']),
        ];
    }

    /**
     * Filters on the departure date. The day count uses the Galápagos sale date.
     *
     * @return array{average_days: string|null, median_days: string|null, bookings: int}
     */
    public function leadTime(MetricWindow $window, MetricScope $scope): array
    {
        [$from, $bindings] = $this->soldBookingFrom($window, $scope);
        $sql = 'WITH days AS (
            SELECT bookings.id AS id,
                DATEDIFF(departures.`date`, DATE(CONVERT_TZ(bookings.created_at, \'+00:00\', \'-06:00\'))) AS lead_days
            '.$from.'
        )
        SELECT
            (SELECT AVG(lead_days) FROM days) AS average_days,
            (SELECT AVG(mid.lead_days) FROM (
                SELECT lead_days,
                    ROW_NUMBER() OVER (ORDER BY lead_days, id) AS rn,
                    COUNT(*) OVER () AS cnt
                FROM days
            ) mid
            WHERE mid.rn IN (FLOOR((mid.cnt + 1) / 2), CEIL((mid.cnt + 1) / 2))
            ) AS median_days,
            (SELECT COUNT(*) FROM days) AS bookings';

        $row = DB::selectOne($sql, $bindings);

        return [
            'average_days' => $this->oneDecimal($row->average_days ?? null),
            'median_days' => $this->oneDecimal($row->median_days ?? null),
            'bookings' => (int) ($row->bookings ?? 0),
        ];
    }

    /**
     * Filters on the departure date. Cruise revenue excludes extras and fees.
     *
     * @return array{rows: list<array{channel: string, group: string, bookings: int, revenue: int}>}
     */
    public function channelMix(MetricWindow $window, MetricScope $scope): array
    {
        [$from, $bindings] = $this->soldBookingFrom($window, $scope);
        $sql = 'SELECT bookings.channel_of_origin AS channel, COUNT(*) AS bookings, COALESCE(SUM(bookings.total), 0) AS revenue '
            .$from.' GROUP BY bookings.channel_of_origin ORDER BY revenue DESC, channel ASC';

        $rows = [];

        foreach (DB::select($sql, $bindings) as $row) {
            $channel = (string) $row->channel;
            $enum = ChannelOfOrigin::tryFrom($channel);
            $group = $enum instanceof ChannelOfOrigin
                ? (CommissionScan::isTradeChannel($enum) ? CommissionScan::tradeGroupName() : $enum->group()->value)
                : $channel;

            $rows[] = [
                'channel' => $channel,
                'group' => $group,
                'bookings' => (int) $row->bookings,
                'revenue' => (int) $row->revenue,
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * Filters on the departure date. Counts only — the country code is not a named person (O8).
     *
     * @return array{rows: list<array{country_code: string, guests: int}>, unknown: int}
     */
    public function nationalityMix(MetricWindow $window, MetricScope $scope): array
    {
        [$where, $bindings] = $this->soldBookingWhere($window, $scope);
        $sql = 'SELECT COALESCE(NULLIF(guests.nationality, \'\'), \'\') AS country_code, COUNT(*) AS guests
            FROM guests
            INNER JOIN bookings ON bookings.id = guests.booking_id
            INNER JOIN departures ON departures.id = bookings.departure_id
            WHERE '.$where.'
            GROUP BY country_code
            ORDER BY guests DESC, country_code ASC';

        $rows = [];
        $unknown = 0;

        foreach (DB::select($sql, $bindings) as $row) {
            $code = (string) $row->country_code;
            $guests = (int) $row->guests;

            if ($code === '') {
                $unknown += $guests;

                continue;
            }

            $rows[] = [
                'country_code' => $code,
                'guests' => $guests,
            ];
        }

        return [
            'rows' => $rows,
            'unknown' => $unknown,
        ];
    }

    /**
     * Filters on the response date, the same instant bounds as the guest-experience view.
     *
     * @return array{average_score: string|null, responses: int, promoters: int, passives: int, detractors: int}
     */
    public function nps(MetricWindow $window, MetricScope $scope): array
    {
        $rules = $this->config->businessRules()->nps;
        $alert = $rules->alertBelow;
        $review = $rules->reviewRequestFrom;

        $sql = 'SELECT
            COALESCE(SUM(guest_responses.score), 0) AS score_sum,
            COUNT(*) AS responses,
            COALESCE(SUM(CASE WHEN guest_responses.score < ? THEN 1 ELSE 0 END), 0) AS detractors,
            COALESCE(SUM(CASE WHEN guest_responses.score >= ? AND guest_responses.score >= ? THEN 1 ELSE 0 END), 0) AS promoters,
            COALESCE(SUM(CASE WHEN guest_responses.score >= ? AND guest_responses.score < ? THEN 1 ELSE 0 END), 0) AS passives
            FROM guest_responses';
        $bindings = [$alert, $alert, $review, $alert, $review];

        if ($scope->restrictsBookings()) {
            $sql .= ' INNER JOIN bookings ON bookings.id = guest_responses.booking_id
                INNER JOIN departures ON departures.id = bookings.departure_id';
        }

        $sql .= ' WHERE guest_responses.responded_at >= ? AND guest_responses.responded_at <= ?';
        $bindings[] = BusinessTime::dayStartUtc($window->from)->toDateTimeString();
        $bindings[] = BusinessTime::dayEndUtc($window->to)->toDateTimeString();

        if ($scope->restrictsBookings()) {
            if ($scope->propertyId !== null) {
                $sql .= ' AND departures.property_id = ?';
                $bindings[] = $scope->propertyId;
            }

            if ($scope->itineraryId !== null) {
                $sql .= ' AND departures.itinerary_id = ?';
                $bindings[] = $scope->itineraryId;
            }

            [$scopeSql, $scopeBindings] = $this->bookingScopeSql('bookings', $scope);
            $sql .= $scopeSql;
            array_push($bindings, ...$scopeBindings);
        }

        $row = DB::selectOne($sql, $bindings);
        $responses = (int) ($row->responses ?? 0);
        $sum = (int) ($row->score_sum ?? 0);

        return [
            'average_score' => $responses === 0 ? null : number_format($sum / $responses, 1, '.', ''),
            'responses' => $responses,
            'promoters' => (int) ($row->promoters ?? 0),
            'passives' => (int) ($row->passives ?? 0),
            'detractors' => (int) ($row->detractors ?? 0),
        ];
    }

    /**
     * Filters on the departure date. Amounts use the accrual status SQL.
     * Paid is the payout row, matching the agencies screen.
     *
     * @return array{blocked: int, earned: int, payable: int, paid: int}
     */
    public function commissions(MetricWindow $window, MetricScope $scope): array
    {
        $rules = $this->config->businessRules();
        $status = Accrual::statusSql();
        $amount = 'CASE WHEN bookings.commission_pct IS NOT NULL THEN ROUND(bookings.total * bookings.commission_pct / 100) ELSE 0 END';
        [$scopeSql, $scopeBindings] = $this->bookingScopeSql('bookings', $scope);

        $sql = 'SELECT
            COALESCE(SUM(CASE WHEN accrual = \''.CommissionAccrualStatus::Blocked->value.'\' THEN amount ELSE 0 END), 0) AS blocked,
            COALESCE(SUM(CASE WHEN accrual = \''.CommissionAccrualStatus::EarnedOnCompletion->value.'\' THEN amount ELSE 0 END), 0) AS earned,
            COALESCE(SUM(CASE WHEN accrual = \''.CommissionAccrualStatus::Payable->value.'\' THEN amount ELSE 0 END), 0) AS payable,
            COALESCE(SUM(paid_amount), 0) AS paid
            FROM (
                SELECT ('.$status.') AS accrual, '.$amount.' AS amount, commission_payouts.amount AS paid_amount
                FROM bookings
                INNER JOIN agencies ON agencies.id = bookings.agency_id
                INNER JOIN departures ON departures.id = bookings.departure_id
                INNER JOIN itineraries ON itineraries.id = departures.itinerary_id
                LEFT JOIN commission_payouts ON commission_payouts.booking_id = bookings.id
                WHERE agencies.status = ?
                  AND departures.`date` >= ? AND departures.`date` <= ?';

        $bindings = [
            $rules->commission->capPct,
            $rules->commission->payableDaysAfterCruise,
            BusinessTime::now()->toDateString(),
            AgencyStatus::Approved->value,
            $window->from,
            $window->to,
        ];

        if ($scope->propertyId !== null) {
            $sql .= ' AND departures.property_id = ?';
            $bindings[] = $scope->propertyId;
        }

        if ($scope->itineraryId !== null) {
            $sql .= ' AND departures.itinerary_id = ?';
            $bindings[] = $scope->itineraryId;
        }

        $sql .= $scopeSql.') accruals';
        array_push($bindings, ...$scopeBindings);

        $row = DB::selectOne($sql, $bindings);

        return [
            'blocked' => (int) ($row->blocked ?? 0),
            'earned' => (int) ($row->earned ?? 0),
            'payable' => (int) ($row->payable ?? 0),
            'paid' => (int) ($row->paid ?? 0),
        ];
    }

    /**
     * Collected and deposits filter on the payment date. Pending and overdue filter on the departure date.
     * Both are PaymentsKpis, not a second cash query.
     *
     * @return array{collected: int, pending: int, overdue: int, deposit_share_pct: int|null}
     */
    public function cash(MetricWindow $window, MetricScope $scope, User $actor): array
    {
        $kpis = PaymentsKpis::for($actor, $window->from, $window->to, $scope);
        $collected = $kpis['collected'];

        return [
            'collected' => $collected,
            'pending' => $kpis['pending'],
            'overdue' => $kpis['overdue_amount'],
            'deposit_share_pct' => $collected === 0 ? null : Rounding::halfUp($kpis['deposits'] * 100 / $collected),
        ];
    }

    /**
     * @return array{revenue: int, sold: int, sellable: int}
     */
    private function revenueAndBerths(MetricWindow $window, MetricScope $scope): array
    {
        [$berths, $berthBindings] = $this->departureBerthsSql($window, $scope);
        [$from, $revenueBindings] = $this->soldBookingFrom($window, $scope);

        $sql = 'SELECT
            (SELECT COALESCE(SUM(bookings.total), 0) '.$from.') AS revenue,
            COALESCE(SUM(CASE WHEN is_charter = 1 THEN cabins ELSE sold_claims END), 0) AS sold,
            COALESCE(SUM(GREATEST(cabins - blocked, 0)), 0) AS sellable
            FROM ('.$berths.') berths';

        $row = DB::selectOne($sql, [...$revenueBindings, ...$berthBindings]);

        return [
            'revenue' => (int) ($row->revenue ?? 0),
            'sold' => (int) ($row->sold ?? 0),
            'sellable' => (int) ($row->sellable ?? 0),
        ];
    }

    /**
     * @return array{0: string, 1: list<int|string>}
     */
    private function departureBerthsSql(MetricWindow $window, MetricScope $scope): array
    {
        [$soldScope, $soldBindings] = $this->bookingScopeSql('sb', $scope);
        [$charterScope, $charterBindings] = $this->bookingScopeSql('cb', $scope);
        $statuses = $this->charterSoldStatuses();
        $statusIn = implode(', ', array_fill(0, count($statuses), '?'));

        $sql = 'SELECT departures.id AS id, departures.`date` AS departure_date, properties.code AS property_code,
            (SELECT COUNT(*) FROM cabins WHERE cabins.property_id = departures.property_id) AS cabins,
            (SELECT COUNT(*) FROM cabin_claims blk
                WHERE blk.departure_id = departures.id AND blk.released_at IS NULL AND blk.kind = ?) AS blocked,
            (SELECT COUNT(*) FROM cabin_claims cc
                INNER JOIN bookings sb ON sb.id = cc.holder_id AND cc.holder_type = ?
                WHERE cc.departure_id = departures.id AND cc.released_at IS NULL AND cc.kind = ?
                  AND sb.deleted_at IS NULL'.$soldScope.') AS sold_claims,
            (CASE WHEN EXISTS (
                SELECT 1 FROM bookings cb
                WHERE cb.departure_id = departures.id AND cb.deleted_at IS NULL
                  AND cb.type = ? AND cb.status IN ('.$statusIn.')'.$charterScope.'
            ) THEN 1 ELSE 0 END) AS is_charter
            FROM departures
            INNER JOIN properties ON properties.id = departures.property_id
            WHERE departures.`date` >= ? AND departures.`date` <= ?';

        $bindings = [
            ClaimKind::Block->value,
            'booking',
            ClaimKind::Booking->value,
            ...$soldBindings,
            BookingType::Charter->value,
            ...$statuses,
            ...$charterBindings,
            $window->from,
            $window->to,
        ];

        if ($scope->propertyId !== null) {
            $sql .= ' AND departures.property_id = ?';
            $bindings[] = $scope->propertyId;
        }

        if ($scope->itineraryId !== null) {
            $sql .= ' AND departures.itinerary_id = ?';
            $bindings[] = $scope->itineraryId;
        }

        return [$sql, $bindings];
    }

    /**
     * Bookings that hold a sold berth in the window: an active booking claim, or a charter on a sold-claim status.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private function soldBookingFrom(MetricWindow $window, MetricScope $scope): array
    {
        [$where, $bindings] = $this->soldBookingWhere($window, $scope);

        return ['FROM bookings INNER JOIN departures ON departures.id = bookings.departure_id WHERE '.$where, $bindings];
    }

    /**
     * @return array{0: string, 1: list<int|string>}
     */
    private function soldBookingWhere(MetricWindow $window, MetricScope $scope): array
    {
        $statuses = $this->charterSoldStatuses();
        $statusIn = implode(', ', array_fill(0, count($statuses), '?'));
        [$scopeSql, $scopeBindings] = $this->bookingScopeSql('bookings', $scope);

        $sql = 'bookings.deleted_at IS NULL AND departures.`date` >= ? AND departures.`date` <= ?';
        $bindings = [$window->from, $window->to];

        if ($scope->propertyId !== null) {
            $sql .= ' AND departures.property_id = ?';
            $bindings[] = $scope->propertyId;
        }

        if ($scope->itineraryId !== null) {
            $sql .= ' AND departures.itinerary_id = ?';
            $bindings[] = $scope->itineraryId;
        }

        $sql .= $scopeSql;
        array_push($bindings, ...$scopeBindings);

        $sql .= ' AND (
            EXISTS (
                SELECT 1 FROM cabin_claims cc
                WHERE cc.holder_type = ? AND cc.holder_id = bookings.id
                  AND cc.departure_id = bookings.departure_id
                  AND cc.released_at IS NULL AND cc.kind = ?
            )
            OR (bookings.type = ? AND bookings.status IN ('.$statusIn.'))
        )';
        array_push($bindings, 'booking', ClaimKind::Booking->value, BookingType::Charter->value, ...$statuses);

        return [$sql, $bindings];
    }

    /**
     * Agency and channel only. Property and itinerary filter the departure.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private function bookingScopeSql(string $alias, MetricScope $scope): array
    {
        $sql = '';
        $bindings = [];

        if ($scope->agencyId !== null) {
            $sql .= ' AND '.$alias.'.agency_id = ?';
            $bindings[] = $scope->agencyId;
        }

        if ($scope->channel !== null) {
            $values = ChannelOfOrigin::valuesInGroup($scope->channel);
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $sql .= ' AND '.$alias.'.channel_of_origin IN ('.$placeholders.')';
            array_push($bindings, ...$values);
        }

        return [$sql, $bindings];
    }

    /**
     * Statuses that hold a booking claim (G9). A request hold is not a sold berth.
     *
     * @return list<string>
     */
    private function charterSoldStatuses(): array
    {
        return [
            BookingStatus::PendingPayment->value,
            BookingStatus::Confirmed->value,
            BookingStatus::FullyPaid->value,
            BookingStatus::OnBoard->value,
            BookingStatus::Completed->value,
            BookingStatus::Overdue->value,
            BookingStatus::OnHoldAgency->value,
        ];
    }

    /**
     * @return array{id: int, date: string, property_code: string, sold_berths: int, sellable_berths: int, occupancy: string|null}
     */
    private function berthRow(object $row): array
    {
        $cabins = (int) $row->cabins;
        $blocked = (int) $row->blocked;
        $sold = (int) $row->is_charter === 1 ? $cabins : (int) $row->sold_claims;
        $sellable = max(0, $cabins - $blocked);

        return [
            'id' => (int) $row->id,
            'date' => (string) $row->departure_date,
            'property_code' => (string) $row->property_code,
            'sold_berths' => $sold,
            'sellable_berths' => $sellable,
            'occupancy' => $this->ratio($sold, $sellable),
        ];
    }

    private function ratio(int $numerator, int $denominator): ?string
    {
        if ($denominator === 0) {
            return null;
        }

        $scale = 10000;
        $scaled = $numerator * $scale;
        $whole = intdiv($scaled, $denominator);
        $remainder = $scaled % $denominator;

        if ($remainder * 2 >= $denominator) {
            $whole++;
        }

        $units = intdiv($whole, $scale);
        $fraction = str_pad((string) ($whole % $scale), 4, '0', STR_PAD_LEFT);

        return $units.'.'.$fraction;
    }

    private function oneDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 1, '.', '');
    }
}
