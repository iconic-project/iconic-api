<?php

declare(strict_types=1);

namespace App\Support\Config\Documents\Rates;

use App\Enums\RoomTypeStatus;
use App\Models\RoomType;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use JsonException;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Shape version 2 of the rates document. Local and testing take the hotel
 * fixture. Everywhere else the lists are empty so a night has no rate until
 * real prices are published.
 */
final class RatesV2
{
    public const APPROVAL_REFERENCE = 'Sprint 18: rates v2 added (09 H8)';

    public const SCHEMA_VERSION = 2;

    /**
     * @return array{
     *     schema_version: int,
     *     seasons: list<array{code: string, name: string, from: string, to: string}>,
     *     room_rates: list<array{room_type: string, season: string, nightly: int}>,
     *     occupancy: array{extra_adult_nightly: int, extra_child_nightly: int, single_occupancy_pct: int},
     *     day_of_week: array<int, int>,
     *     length_of_stay: list<array{min_nights: int, discount_pct: int}>,
     *     supplements: list<array{code: string, label: string, from: string, to: string, per_night: int, basis: string}>,
     *     rate_plans: list<array{code: string, name: string, default: bool, adjust_pct: int, refundable: bool, deposit_pct: int, balance_days: int, cancellation: string, meal_plan: string}>
     * }
     */
    public static function keys(): array
    {
        if (! self::demo()) {
            return self::empty();
        }

        $fixture = self::fixture();
        $roomRates = [];

        foreach ($fixture['room_rates'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $roomRates[] = [
                'room_type' => (string) ($row['room_type'] ?? ''),
                'season' => (string) ($row['season'] ?? ''),
                'nightly' => (int) ($row['nightly'] ?? 0),
            ];
        }

        $occupancy = is_array($fixture['occupancy'] ?? null) ? $fixture['occupancy'] : [];
        $days = is_array($fixture['day_of_week'] ?? null) ? $fixture['day_of_week'] : [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'seasons' => self::seasons($fixture['seasons'] ?? []),
            'room_rates' => self::activeRoomRates($roomRates),
            'occupancy' => [
                'extra_adult_nightly' => (int) ($occupancy['extra_adult_nightly'] ?? 0),
                'extra_child_nightly' => (int) ($occupancy['extra_child_nightly'] ?? 0),
                'single_occupancy_pct' => (int) ($occupancy['single_occupancy_pct'] ?? 0),
            ],
            'day_of_week' => DayOfWeek::fromArray($days)->toArray(),
            'length_of_stay' => self::lengthOfStay($fixture['length_of_stay'] ?? []),
            'supplements' => self::supplements($fixture['supplements'] ?? []),
            'rate_plans' => self::ratePlans($fixture['rate_plans'] ?? []),
        ];
    }

    /**
     * @return array{
     *     schema_version: int,
     *     seasons: list<array{code: string, name: string, from: string, to: string}>,
     *     room_rates: list<array{room_type: string, season: string, nightly: int}>,
     *     occupancy: array{extra_adult_nightly: int, extra_child_nightly: int, single_occupancy_pct: int},
     *     day_of_week: array<int, int>,
     *     length_of_stay: list<array{min_nights: int, discount_pct: int}>,
     *     supplements: list<array{code: string, label: string, from: string, to: string, per_night: int, basis: string}>,
     *     rate_plans: list<array{code: string, name: string, default: bool, adjust_pct: int, refundable: bool, deposit_pct: int, balance_days: int, cancellation: string, meal_plan: string}>
     * }
     */
    public static function empty(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'seasons' => [],
            'room_rates' => [],
            'occupancy' => [
                'extra_adult_nightly' => 0,
                'extra_child_nightly' => 0,
                'single_occupancy_pct' => 0,
            ],
            'day_of_week' => DayOfWeek::fromArray([])->toArray(),
            'length_of_stay' => [],
            'supplements' => [],
            'rate_plans' => [],
        ];
    }

    private static function demo(): bool
    {
        $application = self::application();

        if (! $application instanceof Application) {
            return true;
        }

        try {
            return $application->environment(['local', 'testing']);
        } catch (Throwable) {
            return true;
        }
    }

    private static function application(): ?Application
    {
        $property = new ReflectionProperty(Container::class, 'instance');
        $instance = $property->getValue();

        if (! $instance instanceof Application || ! $instance->bound('env')) {
            return null;
        }

        return $instance;
    }

    /**
     * Room rates survive only for room types that already exist and are active.
     * rules() refuses any other code, and a fresh database has no room types
     * until the hotel seed runs.
     *
     * @param  list<array{room_type: string, season: string, nightly: int}>  $rates
     * @return list<array{room_type: string, season: string, nightly: int}>
     */
    private static function activeRoomRates(array $rates): array
    {
        $codes = self::activeRoomTypeCodes();

        if ($codes === []) {
            return [];
        }

        $kept = [];

        foreach ($rates as $rate) {
            if (in_array($rate['room_type'], $codes, true)) {
                $kept[] = $rate;
            }
        }

        return $kept;
    }

    /**
     * @return list<string>
     */
    private static function activeRoomTypeCodes(): array
    {
        if (! self::application() instanceof Application) {
            return [];
        }

        try {
            /** @var list<string> $codes */
            $codes = RoomType::query()
                ->where('status', RoomTypeStatus::Active->value)
                ->pluck('code')
                ->all();

            return $codes;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $path = self::fixturePath();

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('hotel-seed-data.json is not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('hotel-seed-data.json is not an object.');
        }

        return $decoded;
    }

    private static function fixturePath(): string
    {
        $application = self::application();

        if ($application instanceof Application) {
            return $application->basePath('docs/requirements/examples/hotel-seed-data.json');
        }

        return dirname(__DIR__, 5).'/docs/requirements/examples/hotel-seed-data.json';
    }

    /**
     * @return list<array{code: string, name: string, from: string, to: string}>
     */
    private static function seasons(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $seasons = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $seasons[] = [
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'from' => (string) ($row['from'] ?? ''),
                'to' => (string) ($row['to'] ?? ''),
            ];
        }

        return $seasons;
    }

    /**
     * @return list<array{min_nights: int, discount_pct: int}>
     */
    private static function lengthOfStay(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $bands = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $bands[] = [
                'min_nights' => (int) ($row['min_nights'] ?? 0),
                'discount_pct' => (int) ($row['discount_pct'] ?? 0),
            ];
        }

        return $bands;
    }

    /**
     * @return list<array{code: string, label: string, from: string, to: string, per_night: int, basis: string}>
     */
    private static function supplements(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $supplements = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $supplements[] = [
                'code' => (string) ($row['code'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'from' => (string) ($row['from'] ?? ''),
                'to' => (string) ($row['to'] ?? ''),
                'per_night' => (int) ($row['per_night'] ?? 0),
                'basis' => (string) ($row['basis'] ?? ''),
            ];
        }

        return $supplements;
    }

    /**
     * @return list<array{code: string, name: string, default: bool, adjust_pct: int, refundable: bool, deposit_pct: int, balance_days: int, cancellation: string, meal_plan: string}>
     */
    private static function ratePlans(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $plans = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $plans[] = [
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'default' => $row['default'] === true || $row['default'] === 1 || $row['default'] === '1',
                'adjust_pct' => (int) ($row['adjust_pct'] ?? 0),
                'refundable' => $row['refundable'] === true || $row['refundable'] === 1 || $row['refundable'] === '1',
                'deposit_pct' => (int) ($row['deposit_pct'] ?? 0),
                'balance_days' => (int) ($row['balance_days'] ?? 0),
                'cancellation' => (string) ($row['cancellation'] ?? ''),
                'meal_plan' => (string) ($row['meal_plan'] ?? ''),
            ];
        }

        return $plans;
    }
}
