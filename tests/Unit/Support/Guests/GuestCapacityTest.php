<?php

declare(strict_types=1);

use App\Enums\BookingType;
use App\Models\RoomType;
use App\Support\Config\Documents\EngineSettingsDocument;
use App\Support\Guests\GuestCapacity;

test('a room uses the room type occupancy and a charter uses max per property', function (): void {
    $guests = EngineSettingsDocument::fromArray(EngineSettingsDocument::initial())->guests;
    $roomType = new RoomType(['max_occupancy' => 4]);

    expect(GuestCapacity::max(BookingType::Room, $guests, $roomType))->toBe(4);
    expect(GuestCapacity::max(BookingType::Charter, $guests))->toBe($guests->maxPerProperty);
});
