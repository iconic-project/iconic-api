<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Enums\BookingStatus;

/**
 * History rows keep the status values they were written with.
 * Display maps the retired booking statuses onto the current labels.
 */
final class LegacyStatus
{
    public static function value(string $stored): string
    {
        return match ($stored) {
            'ON_BOARD' => BookingStatus::InHouse->value,
            'COMPLETED' => BookingStatus::CheckedOut->value,
            default => $stored,
        };
    }

    public static function text(string $stored): string
    {
        return strtr($stored, [
            'ON_BOARD' => 'IN_HOUSE',
            'ON BOARD' => 'IN HOUSE',
            'COMPLETED' => 'CHECKED OUT',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function payload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $mapped = $payload;

        if (isset($mapped['status']) && is_string($mapped['status'])) {
            $mapped['status'] = self::value($mapped['status']);
        }

        if (isset($mapped['what']) && is_string($mapped['what'])) {
            $mapped['what'] = self::text($mapped['what']);
        }

        return $mapped;
    }
}
