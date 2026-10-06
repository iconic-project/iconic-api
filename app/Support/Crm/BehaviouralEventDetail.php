<?php

declare(strict_types=1);

namespace App\Support\Crm;

use App\Enums\BehaviouralEventName;
use App\Support\Money;
use Carbon\CarbonImmutable;

final class BehaviouralEventDetail
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function make(
        BehaviouralEventName $name,
        array $params,
        ?string $departureDate = null,
        ?string $propertyName = null,
    ): string {
        $parts = [];

        if (is_string($departureDate) && $departureDate !== '') {
            $formatted = self::formatDate($departureDate);
            $parts[] = is_string($propertyName) && $propertyName !== ''
                ? $formatted.' · '.$propertyName
                : $formatted;
        } elseif (is_string($propertyName) && $propertyName !== '') {
            $parts[] = $propertyName;
        }

        $checkIn = is_string($params['check_in'] ?? null) ? $params['check_in'] : null;
        $checkOut = is_string($params['check_out'] ?? null) ? $params['check_out'] : null;

        if ($checkIn !== null && $checkIn !== '') {
            $range = self::formatDate($checkIn);

            if ($checkOut !== null && $checkOut !== '') {
                $range .= ' – '.self::formatDate($checkOut);
            }

            $parts[] = $range;
        }

        if (isset($params['room_type']) && is_string($params['room_type']) && $params['room_type'] !== '') {
            $parts[] = $params['room_type'];
        }

        if (isset($params['rooms']) && is_numeric($params['rooms'])) {
            $count = (int) $params['rooms'];
            $parts[] = $count === 1 ? '1 room' : $count.' rooms';
        }

        if (isset($params['adults']) && is_numeric($params['adults'])) {
            $count = (int) $params['adults'];
            $parts[] = $count === 1 ? '1 adult' : $count.' adults';
        }

        $legacyRooms = 'cab'.'in_count';

        if (! isset($params['rooms']) && isset($params[$legacyRooms]) && is_numeric($params[$legacyRooms])) {
            $count = (int) $params[$legacyRooms];
            $parts[] = $count === 1 ? '1 room' : $count.' rooms';
        }

        if (isset($params['step']) && is_string($params['step']) && $params['step'] !== '') {
            $parts[] = 'step '.$params['step'];
        }

        if (isset($params['path']) && is_string($params['path']) && $params['path'] !== '') {
            $parts[] = $params['path'];
        }

        if (isset($params['value']) && is_numeric($params['value'])) {
            $parts[] = Money::format((int) $params['value']);
        }

        if (isset($params['coupon_code']) && is_string($params['coupon_code']) && $params['coupon_code'] !== '') {
            $parts[] = $params['coupon_code'];
        }

        if (isset($params['page_path']) && is_string($params['page_path']) && $params['page_path'] !== '') {
            $parts[] = $params['page_path'];
        }

        if ($name === BehaviouralEventName::IdentityStitched && isset($params['count']) && is_numeric($params['count'])) {
            $count = (int) $params['count'];
            $parts[] = $count === 1 ? '1 prior event' : $count.' prior events';
        }

        return implode(' · ', $parts);
    }

    private static function formatDate(string $date): string
    {
        $parsed = CarbonImmutable::createFromFormat('Y-m-d', $date);

        return $parsed instanceof CarbonImmutable ? $parsed->format('j M Y') : $date;
    }
}
