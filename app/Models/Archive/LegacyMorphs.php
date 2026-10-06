<?php

declare(strict_types=1);

namespace App\Models\Archive;

/**
 * Morph aliases for rows written before the hotel contract. History still
 * points at these names. They resolve to the archive tables only.
 */
final class LegacyMorphs
{
    /**
     * @return array<string, class-string>
     */
    public static function map(): array
    {
        return [
            'itinerary' => Itinerary::class,
            'departure' => Departure::class,
        ];
    }
}
