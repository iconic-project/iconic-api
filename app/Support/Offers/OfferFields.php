<?php

declare(strict_types=1);

namespace App\Support\Offers;

use App\Enums\OfferChannel;
use App\Enums\OfferType;
use App\Models\Offer;
use BackedEnum;
use DateTimeInterface;

final class OfferFields
{
    /**
     * @return list<string>
     */
    public static function writable(): array
    {
        return [
            'code',
            'name',
            'type',
            'value',
            'value_text',
            'channel',
            'partner',
            'booking_from',
            'booking_to',
            'stay_from',
            'stay_to',
            'min_nights',
            'applies_to_room_types',
            'applies_to_rate_plans',
            'combinable',
            'is_promo_code',
            'badge',
            'show_on_card',
            'show_on_calendar',
            'price_line',
            'terms',
        ];
    }

    public static function equal(string $field, mixed $current, mixed $next): bool
    {
        if (in_array($field, ['applies_to_room_types', 'applies_to_rate_plans'], true)) {
            $left = self::sortedStrings($current);
            $right = self::sortedStrings($next);

            return $left === $right;
        }

        if (in_array($field, ['booking_from', 'booking_to', 'stay_from', 'stay_to'], true)) {
            return self::dateString($current) === self::dateString($next);
        }

        if (in_array($field, ['combinable', 'is_promo_code', 'show_on_card', 'show_on_calendar'], true)) {
            return (bool) $current === (bool) $next;
        }

        if (in_array($field, ['value', 'min_nights'], true)) {
            return self::intOrNull($current) === self::intOrNull($next);
        }

        return self::scalar($current) === self::scalar($next);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function changed(Offer $offer, array $data): array
    {
        $before = [];
        $after = [];

        foreach (self::writable() as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $current = $offer->getAttribute($field);
            $next = $data[$field];

            if (self::equal($field, $current, $next)) {
                continue;
            }

            $before[$field] = self::export($current);
            $after[$field] = self::export($next);
            $offer->setAttribute($field, $next);
        }

        return ['before' => $before, 'after' => $after];
    }

    /**
     * @param  array<string, mixed>  $before
     */
    public static function materialChanged(array $before): bool
    {
        foreach (Offer::materialFields() as $field) {
            if (array_key_exists($field, $before)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function sortedStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if ($item instanceof BackedEnum) {
                $list[] = (string) $item->value;

                continue;
            }

            if (is_string($item) || is_int($item)) {
                $list[] = (string) $item;
            }
        }

        sort($list);

        return $list;
    }

    private static function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private static function scalar(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value)) {
            return $value;
        }

        return $value;
    }

    private static function export(mixed $value): mixed
    {
        if ($value instanceof OfferType || $value instanceof OfferChannel) {
            return $value->value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }
}
