<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Enums\BehaviouralEventName;
use App\Enums\BookingStatus;
use App\Enums\ConsentPurpose;
use App\Enums\DeliveryStatus;
use App\Services\Config\CurrentConfig;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class SegmentCompiler
{
    /**
     * @param  array{match: string, items: list<array<string, mixed>>}  $conditions
     * @return array{sql: string, bindings: list<mixed>}
     */
    public static function compile(array $conditions): array
    {
        $joiner = $conditions['match'] === 'any' ? ' OR ' : ' AND ';
        $parts = [];
        $bindings = [];

        foreach ($conditions['items'] as $item) {
            [$sql, $itemBindings] = self::item($item);
            $parts[] = '('.$sql.')';
            array_push($bindings, ...$itemBindings);
        }

        if ($parts === []) {
            throw new InvalidArgumentException('A segment needs at least one condition.');
        }

        return [
            'sql' => '('.implode($joiner, $parts).')',
            'bindings' => $bindings,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function item(array $item): array
    {
        $field = $item['field'] ?? null;

        return match ($field) {
            'event_count' => self::eventCount($item),
            'stay_date' => self::stayDate($item),
            'arrival_weekday' => self::arrivalWeekday($item),
            'length_of_stay' => self::lengthOfStay($item),
            'room_type' => self::roomType($item),
            'rate_plan' => self::ratePlan($item),
            'festive_departure_views' => self::departureViews($item),
            'booking_count' => self::bookingCount($item),
            'booking_status' => self::bookingStatus($item),
            'active_hold' => self::activeHold($item),
            'lifecycle' => self::lifecycle($item),
            'ltv_band' => self::ltvBand($item),
            'nps' => self::nps($item),
            'consent' => self::consent($item),
            'country' => self::country($item),
            'guest_age' => self::guestAge($item),
            'agency_id' => self::agency($item),
            'campaign' => self::campaign($item),
            'last_activity_days' => self::lastActivity($item),
            'erasure' => self::erasure($item),
            'hard_bounce' => self::hardBounce($item),
            default => throw new InvalidArgumentException('Unknown segment field.'),
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function eventCount(array $item): array
    {
        $event = (string) $item['event'];
        $window = self::window('behavioural_events.occurred_at', $item['within_days'] ?? null);
        $sql = '(
            SELECT COUNT(*)
            FROM behavioural_events
            WHERE behavioural_events.contact_id = contacts.id
              AND behavioural_events.name = ?
              '.$window['sql'].'
        ) '.self::operator($item).' ?';

        return [$sql, [$event, ...$window['bindings'], self::intValue($item)]];
    }

    /**
     * Stored segments still name this field. It counts departure-view events.
     * Festive is a date supplement now, so the count does not read departures.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function departureViews(array $item): array
    {
        $item['event'] = BehaviouralEventName::ViewDeparture->value;

        return self::eventCount($item);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function stayDate(array $item): array
    {
        return self::bookingCompare('bookings.check_in', $item, self::dateValue($item));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function lengthOfStay(array $item): array
    {
        return self::bookingCompare('bookings.nights', $item, self::intValue($item));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function arrivalWeekday(array $item): array
    {
        $days = self::weekdayNumbers($item);

        return [
            self::existsIn('WEEKDAY(bookings.check_in)', $days, 'bookings.contact_id = contacts.id AND bookings.deleted_at IS NULL'),
            $days,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function roomType(array $item): array
    {
        $codes = self::listValue($item);

        return [self::existsJoined(
            'room_types',
            'room_types.id = bookings.room_type_id',
            'room_types.code',
            $codes,
        ), $codes];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function ratePlan(array $item): array
    {
        $codes = self::listValue($item);

        return [self::existsIn(
            'bookings.rate_plan_code',
            $codes,
            'bookings.contact_id = contacts.id AND bookings.deleted_at IS NULL',
        ), $codes];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function bookingCount(array $item): array
    {
        $window = self::window('bookings.created_at', $item['within_days'] ?? null);
        $sql = '(
            SELECT COUNT(*)
            FROM bookings
            WHERE bookings.contact_id = contacts.id
              AND bookings.deleted_at IS NULL
              '.$window['sql'].'
        ) '.self::operator($item).' ?';

        return [$sql, [...$window['bindings'], self::intValue($item)]];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function bookingStatus(array $item): array
    {
        $statuses = self::listValue($item);

        return [self::existsIn('bookings.status', $statuses, 'bookings.contact_id = contacts.id AND bookings.deleted_at IS NULL'), $statuses];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function activeHold(array $item): array
    {
        $status = BookingStatus::Requested->value;
        $sql = 'EXISTS (
            SELECT 1
            FROM bookings
            INNER JOIN booking_requests ON booking_requests.booking_id = bookings.id
            WHERE bookings.contact_id = contacts.id
              AND bookings.deleted_at IS NULL
              AND bookings.status = \''.$status.'\'
              AND booking_requests.hold_expired_at IS NULL
        )';

        if ($item['value'] !== true) {
            $sql = 'NOT '.$sql;
        }

        return [$sql, []];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function lifecycle(array $item): array
    {
        $values = self::listValue($item);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return ['('.ContactDerived::lifecycleSql().') IN ('.$placeholders.')', $values];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function ltvBand(array $item): array
    {
        $values = self::listValue($item);
        $crm = app(CurrentConfig::class)->businessRules()->crm;
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return ['('.ContactDerived::segmentSql($crm).') IN ('.$placeholders.')', $values];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function nps(array $item): array
    {
        return ['('.ContactDerived::npsSql().') '.self::operator($item).' ?', [self::intValue($item)]];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function consent(array $item): array
    {
        $latest = '(
            SELECT contact_consents.granted
            FROM contact_consents
            WHERE contact_consents.contact_id = contacts.id
              AND contact_consents.purpose = \''.ConsentPurpose::Marketing->value.'\'
            ORDER BY contact_consents.captured_at DESC, contact_consents.id DESC
            LIMIT 1
        )';

        $sql = match ($item['value']) {
            'granted' => $latest.' = 1',
            'withdrawn' => $latest.' = 0',
            'never' => $latest.' IS NULL',
            default => throw new InvalidArgumentException('Unknown consent state.'),
        };

        return [$sql, []];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function country(array $item): array
    {
        $codes = self::listValue($item);

        return [self::existsExpression('UPPER(contacts.country)', $codes), $codes];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function guestAge(array $item): array
    {
        $range = $item['value'];

        if (! is_array($range) || ! isset($range[0], $range[1])) {
            throw new InvalidArgumentException('Guest age needs a minimum and a maximum.');
        }

        $sql = 'EXISTS (
            SELECT 1
            FROM guests
            INNER JOIN bookings ON bookings.id = guests.booking_id
            WHERE bookings.contact_id = contacts.id
              AND bookings.deleted_at IS NULL
              AND guests.dob IS NOT NULL
              AND (
                YEAR(bookings.check_in) - YEAR(guests.dob)
                - (DATE_FORMAT(bookings.check_in, \'%m%d\') < DATE_FORMAT(guests.dob, \'%m%d\'))
              ) BETWEEN ? AND ?
        )';

        return [$sql, [(int) $range[0], (int) $range[1]]];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function agency(array $item): array
    {
        $ids = self::listValue($item);

        return [self::existsIn('bookings.agency_id', $ids, 'bookings.contact_id = contacts.id AND bookings.deleted_at IS NULL'), $ids];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function campaign(array $item): array
    {
        $key = strtolower(trim((string) $item['value']));
        $touch = "LOWER(JSON_UNQUOTE(JSON_EXTRACT(%s, '$.campaign'))) = ?";
        $sql = '(
            EXISTS (
                SELECT 1 FROM bookings
                WHERE bookings.contact_id = contacts.id
                  AND bookings.deleted_at IS NULL
                  AND ('.sprintf($touch, 'bookings.utm_first').' OR '.sprintf($touch, 'bookings.utm_last').')
            )
            OR '.sprintf($touch, 'contacts.first_touch').'
            OR '.sprintf($touch, 'contacts.last_touch').'
        )';

        return [$sql, [$key, $key, $key, $key]];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function lastActivity(array $item): array
    {
        $sql = 'DATEDIFF(
            ?,
            DATE(GREATEST(
                COALESCE((SELECT MAX(behavioural_events.occurred_at) FROM behavioural_events WHERE behavioural_events.contact_id = contacts.id), \'1000-01-01 00:00:00\'),
                COALESCE((SELECT MAX(bookings.created_at) FROM bookings WHERE bookings.contact_id = contacts.id AND bookings.deleted_at IS NULL), \'1000-01-01 00:00:00\')
            ))
        ) '.self::operator($item).' ?';

        return [$sql, [Carbon::now()->toDateString(), self::intValue($item)]];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function erasure(array $item): array
    {
        $sql = 'EXISTS (SELECT 1 FROM erasure_log WHERE erasure_log.contact_id = contacts.id OR SHA2(LOWER(TRIM(contacts.email)), 256) = erasure_log.email_sha256)';

        if ($item['value'] !== true) {
            $sql = 'NOT '.$sql;
        }

        return [$sql, []];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function hardBounce(array $item): array
    {
        $status = DeliveryStatus::HardBounce->value;
        $sql = 'EXISTS (
            SELECT 1
            FROM deliveries
            LEFT JOIN bookings ON bookings.id = deliveries.booking_id AND bookings.deleted_at IS NULL
            WHERE deliveries.status = \''.$status.'\'
              AND (
                bookings.contact_id = contacts.id
                OR (
                    contacts.email IS NOT NULL
                    AND JSON_CONTAINS(
                        CAST(LOWER(CAST(deliveries.`to` AS CHAR)) AS JSON),
                        JSON_QUOTE(LOWER(contacts.email))
                    )
                )
              )
        )';

        if ($item['value'] !== true) {
            $sql = 'NOT '.$sql;
        }

        return [$sql, []];
    }

    /**
     * @return array{sql: string, bindings: list<mixed>}
     */
    private static function window(string $column, mixed $withinDays): array
    {
        if ($withinDays === null) {
            return ['sql' => '', 'bindings' => []];
        }

        return [
            'sql' => 'AND '.$column.' >= ?',
            'bindings' => [Carbon::now()->subDays((int) $withinDays)->toDateTimeString()],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function operator(array $item): string
    {
        return match ($item['operator'] ?? '') {
            'eq' => '=',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            default => throw new InvalidArgumentException('Unknown segment operator.'),
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function intValue(array $item): int
    {
        $value = $item['value'] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException('Segment comparison needs an integer.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<int|string>
     */
    private static function listValue(array $item): array
    {
        $value = $item['value'] ?? null;

        if (is_array($value) && array_is_list($value)) {
            /** @var list<int|string> $value */
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            return [$value];
        }

        throw new InvalidArgumentException('Segment list needs a value.');
    }

    /**
     * @param  list<int|string>  $values
     */
    private static function existsIn(string $column, array $values, string $where): string
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return 'EXISTS (
            SELECT 1 FROM bookings
            WHERE '.$where.'
              AND '.$column.' IN ('.$placeholders.')
        )';
    }

    /**
     * @param  list<int|string>  $values
     */
    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<mixed>}
     */
    private static function bookingCompare(string $column, array $item, int|string $value): array
    {
        $sql = 'EXISTS (
            SELECT 1 FROM bookings
            WHERE bookings.contact_id = contacts.id
              AND bookings.deleted_at IS NULL
              AND '.$column.' '.self::operator($item).' ?
        )';

        return [$sql, [$value]];
    }

    /**
     * @param  list<int|string>  $values
     */
    private static function existsJoined(string $table, string $on, string $column, array $values): string
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return 'EXISTS (
            SELECT 1 FROM bookings
            INNER JOIN '.$table.' ON '.$on.'
            WHERE bookings.contact_id = contacts.id
              AND bookings.deleted_at IS NULL
              AND '.$column.' IN ('.$placeholders.')
        )';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function dateValue(array $item): string
    {
        $value = $item['value'] ?? null;

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('Stay date needs a Y-m-d value.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<int>
     */
    private static function weekdayNumbers(array $item): array
    {
        $names = [
            'Monday' => 0,
            'Tuesday' => 1,
            'Wednesday' => 2,
            'Thursday' => 3,
            'Friday' => 4,
            'Saturday' => 5,
            'Sunday' => 6,
        ];
        $numbers = [];

        foreach (self::listValue($item) as $name) {
            if (! is_string($name) || ! array_key_exists($name, $names)) {
                throw new InvalidArgumentException('Arrival weekday needs an English day name.');
            }

            $numbers[] = $names[$name];
        }

        return $numbers;
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function existsExpression(string $expression, array $values): string
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return $expression.' IN ('.$placeholders.')';
    }
}
