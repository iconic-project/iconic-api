<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Enums\BehaviouralEventName;
use App\Enums\BookingStatus;
use App\Enums\ContactLifecycle;
use App\Enums\ContactSegment;
use Illuminate\Validation\ValidationException;

final class SegmentVocabulary
{
    /**
     * @return array{
     *     fields: list<array{field: string, label: string, operators: list<string>, value: string, values?: list<string>, params?: list<array{name: string, type: string, values?: list<string>}>}>,
     *     combinators: list<string>
     * }
     */
    public static function describe(): array
    {
        $compare = ['gt', 'gte', 'eq', 'lt', 'lte'];

        return [
            'combinators' => ['all', 'any'],
            'fields' => [
                [
                    'field' => 'event_count',
                    'label' => 'Behavioural event count',
                    'operators' => $compare,
                    'value' => 'integer',
                    'params' => [
                        [
                            'name' => 'event',
                            'type' => 'enum',
                            'values' => array_map(
                                fn (BehaviouralEventName $name): string => $name->value,
                                BehaviouralEventName::cases(),
                            ),
                        ],
                        ['name' => 'within_days', 'type' => 'integer_or_null'],
                    ],
                ],
                [
                    'field' => 'stay_date',
                    'label' => 'Stay date',
                    'operators' => $compare,
                    'value' => 'date',
                ],
                [
                    'field' => 'arrival_weekday',
                    'label' => 'Arrival weekday',
                    'operators' => ['eq', 'in'],
                    'value' => 'weekday',
                    'values' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
                ],
                [
                    'field' => 'length_of_stay',
                    'label' => 'Length of stay',
                    'operators' => $compare,
                    'value' => 'integer',
                ],
                [
                    'field' => 'room_type',
                    'label' => 'Room type',
                    'operators' => ['eq', 'in'],
                    'value' => 'code',
                ],
                [
                    'field' => 'rate_plan',
                    'label' => 'Rate plan',
                    'operators' => ['eq', 'in'],
                    'value' => 'code',
                ],
                [
                    'field' => 'booking_count',
                    'label' => 'Booking count',
                    'operators' => $compare,
                    'value' => 'integer',
                    'params' => [
                        ['name' => 'within_days', 'type' => 'integer_or_null'],
                    ],
                ],
                [
                    'field' => 'booking_status',
                    'label' => 'Booking status',
                    'operators' => ['eq', 'in'],
                    'value' => 'booking_status',
                    'values' => array_map(
                        fn (BookingStatus $status): string => $status->value,
                        BookingStatus::cases(),
                    ),
                ],
                [
                    'field' => 'active_hold',
                    'label' => 'Active request hold',
                    'operators' => ['eq'],
                    'value' => 'boolean',
                ],
                [
                    'field' => 'lifecycle',
                    'label' => 'Lifecycle',
                    'operators' => ['eq', 'in'],
                    'value' => 'lifecycle',
                    'values' => array_map(
                        fn (ContactLifecycle $lifecycle): string => $lifecycle->value,
                        ContactLifecycle::cases(),
                    ),
                ],
                [
                    'field' => 'ltv_band',
                    'label' => 'Lifetime value band',
                    'operators' => ['eq', 'in'],
                    'value' => 'ltv_band',
                    'values' => array_map(
                        fn (ContactSegment $band): string => $band->value,
                        ContactSegment::cases(),
                    ),
                ],
                [
                    'field' => 'nps',
                    'label' => 'Latest NPS score',
                    'operators' => $compare,
                    'value' => 'integer',
                ],
                [
                    'field' => 'consent',
                    'label' => 'Marketing consent',
                    'operators' => ['eq'],
                    'value' => 'consent',
                    'values' => ['granted', 'withdrawn', 'never'],
                ],
                [
                    'field' => 'country',
                    'label' => 'Country',
                    'operators' => ['eq', 'in'],
                    'value' => 'country',
                ],
                [
                    'field' => 'guest_age',
                    'label' => 'Guest age at arrival',
                    'operators' => ['between'],
                    'value' => 'age_range',
                ],
                [
                    'field' => 'agency_id',
                    'label' => 'Booking agency',
                    'operators' => ['eq', 'in'],
                    'value' => 'integer',
                ],
                [
                    'field' => 'campaign',
                    'label' => 'Campaign exposure',
                    'operators' => ['eq'],
                    'value' => 'string',
                ],
                [
                    'field' => 'last_activity_days',
                    'label' => 'Days since last activity',
                    'operators' => $compare,
                    'value' => 'integer',
                ],
                [
                    'field' => 'erasure',
                    'label' => 'Erasure recorded',
                    'operators' => ['eq'],
                    'value' => 'boolean',
                ],
                [
                    'field' => 'hard_bounce',
                    'label' => 'Hard bounce',
                    'operators' => ['eq'],
                    'value' => 'boolean',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    public static function assertValid(array $conditions): void
    {
        $match = $conditions['match'] ?? null;

        if (! in_array($match, ['all', 'any'], true)) {
            throw ValidationException::withMessages([
                'conditions.match' => ['A segment matches all of its conditions, or any of them.'],
            ]);
        }

        $items = $conditions['items'] ?? null;

        if (! is_array($items) || $items === [] || array_is_list($items) === false) {
            throw ValidationException::withMessages([
                'conditions.items' => ['A segment needs at least one condition.'],
            ]);
        }

        if (count($items) > 20) {
            throw ValidationException::withMessages([
                'conditions.items' => ['A segment can have at most 20 conditions.'],
            ]);
        }

        $catalogue = [];

        foreach (self::describe()['fields'] as $field) {
            $catalogue[$field['field']] = $field;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "conditions.items.{$index}" => ['A condition is a field, an operator and a value.'],
                ]);
            }

            self::assertItem($index, $item, $catalogue);
        }
    }

    /**
     * @param  array<string, array{field: string, label: string, operators: list<string>, value: string, values?: list<string>, params?: list<array{name: string, type: string, values?: list<string>}>}>  $catalogue
     * @param  array<mixed>  $item
     */
    private static function assertItem(int $index, array $item, array $catalogue): void
    {
        $prefix = "conditions.items.{$index}";
        $field = $item['field'] ?? null;

        if (! is_string($field) || ! isset($catalogue[$field])) {
            throw ValidationException::withMessages([
                "{$prefix}.field" => ['This field is not in the segment vocabulary.'],
            ]);
        }

        $spec = $catalogue[$field];
        $operator = $item['operator'] ?? null;

        if (! is_string($operator) || ! in_array($operator, $spec['operators'], true)) {
            throw ValidationException::withMessages([
                "{$prefix}.operator" => ['This operator is not available for that field.'],
            ]);
        }

        $allowed = ['field', 'operator', 'value'];

        foreach ($spec['params'] ?? [] as $param) {
            $allowed[] = $param['name'];
        }

        foreach (array_keys($item) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages([
                    "{$prefix}.{$key}" => ['This condition has a parameter the vocabulary does not allow.'],
                ]);
            }
        }

        self::assertValue($prefix, $spec, $operator, $item['value'] ?? null);
        self::assertParams($prefix, $spec, $item);
    }

    /**
     * @param  array{field: string, operators: list<string>, value: string, values?: list<string>, params?: list<array{name: string, type: string, values?: list<string>}>}  $spec
     */
    private static function assertValue(string $prefix, array $spec, string $operator, mixed $value): void
    {
        $kind = $spec['value'];

        $ok = match ($kind) {
            'integer' => self::isWholeNumber($value, $spec['field'] === 'nps' ? 0 : 0, $spec['field'] === 'nps' ? 10 : null),
            'boolean' => is_bool($value),
            'string' => is_string($value) && trim($value) !== '' && strlen($value) <= 120,
            'consent' => in_array($value, $spec['values'] ?? [], true),
            'booking_status', 'lifecycle', 'ltv_band', 'weekday' => self::enumValue($operator, $value, $spec['values'] ?? []),
            'country' => self::countryValue($operator, $value),
            'age_range' => self::ageRange($value),
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
            'code' => self::codeValue($operator, $value),
            default => false,
        };

        if ($kind === 'integer' && $spec['field'] === 'agency_id') {
            $ok = self::agencyValue($operator, $value);
        }

        if (! $ok) {
            throw ValidationException::withMessages([
                "{$prefix}.value" => ['This value is not allowed for that field.'],
            ]);
        }
    }

    /**
     * @param  array{params?: list<array{name: string, type: string, values?: list<string>}>}  $spec
     * @param  array<mixed>  $item
     */
    private static function assertParams(string $prefix, array $spec, array $item): void
    {
        foreach ($spec['params'] ?? [] as $param) {
            $name = $param['name'];

            if ($name === 'event') {
                $event = $item['event'] ?? null;

                if (! is_string($event) || ! in_array($event, $param['values'] ?? [], true)) {
                    throw ValidationException::withMessages([
                        "{$prefix}.event" => ['The event is not in the behavioural vocabulary.'],
                    ]);
                }
            }

            if ($name === 'within_days' && array_key_exists('within_days', $item) && $item['within_days'] !== null) {
                if (! is_int($item['within_days']) || $item['within_days'] < 1 || $item['within_days'] > 3650) {
                    throw ValidationException::withMessages([
                        "{$prefix}.within_days" => ['The window is a number of days, or empty for all time.'],
                    ]);
                }
            }
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function enumValue(string $operator, mixed $value, array $allowed): bool
    {
        if ($operator === 'eq') {
            return is_string($value) && in_array($value, $allowed, true);
        }

        return self::stringList($value, $allowed);
    }

    private static function countryValue(string $operator, mixed $value): bool
    {
        $valid = fn (mixed $code): bool => is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1;

        if ($operator === 'eq') {
            return $valid($value);
        }

        if (! is_array($value) || $value === [] || array_is_list($value) === false || count($value) > 50) {
            return false;
        }

        foreach ($value as $code) {
            if (! $valid($code)) {
                return false;
            }
        }

        return true;
    }

    private static function ageRange(mixed $value): bool
    {
        if (! is_array($value) || count($value) !== 2 || array_is_list($value) === false) {
            return false;
        }

        $min = $value[0];
        $max = $value[1];

        return is_int($min) && is_int($max) && $min >= 0 && $max <= 120 && $min <= $max;
    }

    private static function codeValue(string $operator, mixed $value): bool
    {
        $valid = fn (mixed $code): bool => is_string($code) && $code !== '' && strlen($code) <= 32;

        if ($operator === 'eq') {
            return $valid($value);
        }

        if (! is_array($value) || $value === [] || array_is_list($value) === false || count($value) > 50) {
            return false;
        }

        foreach ($value as $code) {
            if (! $valid($code)) {
                return false;
            }
        }

        return true;
    }

    private static function agencyValue(string $operator, mixed $value): bool
    {
        if ($operator === 'eq') {
            return is_int($value) && $value > 0;
        }

        if (! is_array($value) || $value === [] || array_is_list($value) === false || count($value) > 50) {
            return false;
        }

        foreach ($value as $id) {
            if (! is_int($id) || $id < 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function stringList(mixed $value, array $allowed): bool
    {
        if (! is_array($value) || $value === [] || array_is_list($value) === false || count($value) > 50) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private static function isWholeNumber(mixed $value, int $min, ?int $max): bool
    {
        if (! is_int($value) || $value < $min) {
            return false;
        }

        return $max === null || $value <= $max;
    }
}
