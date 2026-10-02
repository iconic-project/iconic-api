<?php

declare(strict_types=1);

namespace App\Support\Departures;

use App\Enums\DepartureStatus;

/**
 * Maps a prototype / seed-data.json departure row onto departures columns.
 */
final class SeedMapper
{
    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     reference: string,
     *     property_code: string,
     *     itinerary_code: string,
     *     status: DepartureStatus,
     *     urgency_threshold: int,
     *     waitlist_enabled: bool,
     *     public_note: string|null,
     *     date: string,
     *     festive: bool
     * }
     */
    public static function fromPrototype(array $row): array
    {
        $note = is_string($row['note'] ?? null) ? $row['note'] : '';

        return [
            'reference' => (string) $row['id'],
            'property_code' => (string) $row['property'],
            'itinerary_code' => (string) $row['itin'],
            'status' => DepartureStatus::from((string) $row['status']),
            'urgency_threshold' => (int) $row['thr'],
            'waitlist_enabled' => (bool) $row['wait'],
            'public_note' => $note === '' ? null : $note,
            'date' => (string) $row['date'],
            'festive' => (bool) $row['festive'],
        ];
    }
}
