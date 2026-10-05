<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use JsonException;
use ReflectionProperty;
use Throwable;

/**
 * Hotel taxes. Local and testing copy hotel-seed-data.json `taxes`.
 * That fixture has no list, so both environments publish an empty list
 * until real taxes are entered. Production is always empty.
 */
final class Taxes
{
    public const APPROVAL_REFERENCE = 'Sprint 18: taxes added (09 H9)';

    /**
     * @return list<array{
     *     code: string,
     *     label: string,
     *     basis: string,
     *     amount: int,
     *     child_exempt_under_age: int|null,
     *     charged: bool,
     *     shown_in_price_panel: bool
     * }>
     */
    public static function list(): array
    {
        if (! self::demo()) {
            return [];
        }

        $decoded = self::fixture();
        $rows = $decoded['taxes'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $taxes = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $taxes[] = Tax::fromArray($row)->toArray();
        }

        return $taxes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $path = dirname(__DIR__, 4).'/docs/requirements/examples/hotel-seed-data.json';

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
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
}
