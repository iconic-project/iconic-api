<?php

declare(strict_types=1);

use App\Support\Rooms\RoomTypeOccupancy;

test('occupancy is valid when each limit fits inside max occupancy and max adults is at least 1', function (): void {
    expect(RoomTypeOccupancy::errors(2, 3, 3, 2))->toBe([]);
    expect(RoomTypeOccupancy::errors(3, 3, 1, 0))->toBe([]);
    expect(RoomTypeOccupancy::errors(0, 1, 1, 1))->toBe([]);
});

test('occupancy rejects a base, adult or child limit above max occupancy', function (): void {
    expect(RoomTypeOccupancy::errors(4, 3, 3, 2))->toBe([
        'base_occupancy' => 'Base occupancy cannot exceed max occupancy.',
    ]);
    expect(RoomTypeOccupancy::errors(2, 3, 4, 2))->toBe([
        'max_adults' => 'Max adults cannot exceed max occupancy.',
    ]);
    expect(RoomTypeOccupancy::errors(2, 3, 3, 4))->toBe([
        'max_children' => 'Max children cannot exceed max occupancy.',
    ]);
});

test('occupancy rejects max adults below 1', function (): void {
    expect(RoomTypeOccupancy::errors(0, 3, 0, 0))->toBe([
        'max_adults' => 'Max adults must be at least 1.',
    ]);
});

test('occupancy reports every broken limit together', function (): void {
    expect(RoomTypeOccupancy::errors(5, 2, 0, 4))->toBe([
        'max_adults' => 'Max adults must be at least 1.',
        'base_occupancy' => 'Base occupancy cannot exceed max occupancy.',
        'max_children' => 'Max children cannot exceed max occupancy.',
    ]);
});
