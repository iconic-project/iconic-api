<?php

declare(strict_types=1);

namespace App\Services\Engine;

use Illuminate\Support\Facades\Cache;

final class EngineFeedVersion
{
    public const KEY = 'engine:feed:version';

    public static function current(): int
    {
        return (int) Cache::get(self::KEY, 0);
    }

    public static function bump(): void
    {
        if (Cache::add(self::KEY, 1)) {
            return;
        }

        Cache::increment(self::KEY);
    }

    public static function payloadKey(?int $version = null): string
    {
        return 'engine:feed:'.($version ?? self::current());
    }

    public static function roomsKey(int $stayId): string
    {
        return 'engine:rooms:'.$stayId;
    }

    public static function propertyKey(?int $version = null): string
    {
        return 'engine:property:'.($version ?? self::current());
    }

    public static function calendarKey(string $month, int $months, int $adults, int $children, ?int $version = null): string
    {
        return 'engine:calendar:'.($version ?? self::current()).':'.$month.':'.$months.':'.$adults.':'.$children;
    }
}
