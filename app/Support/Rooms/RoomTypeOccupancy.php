<?php

declare(strict_types=1);

namespace App\Support\Rooms;

final class RoomTypeOccupancy
{
    /**
     * @return array<string, string>
     */
    public static function errors(int $baseOccupancy, int $maxOccupancy, int $maxAdults, int $maxChildren): array
    {
        $errors = [];

        if ($maxAdults < 1) {
            $errors['max_adults'] = 'Max adults must be at least 1.';
        }

        if ($baseOccupancy > $maxOccupancy) {
            $errors['base_occupancy'] = 'Base occupancy cannot exceed max occupancy.';
        }

        if ($maxAdults > $maxOccupancy) {
            $errors['max_adults'] = 'Max adults cannot exceed max occupancy.';
        }

        if ($maxChildren > $maxOccupancy) {
            $errors['max_children'] = 'Max children cannot exceed max occupancy.';
        }

        return $errors;
    }
}
