<?php

declare(strict_types=1);

use App\Enums\BookingType;
use App\Support\Config\Documents\EngineSettingsDocument;
use App\Support\Guests\GuestCapacity;

test('a cabin uses guests.max_per_cabin and a charter uses guests.max_per_property', function (): void {
    $guests = EngineSettingsDocument::fromArray(EngineSettingsDocument::initial())->guests;

    expect(GuestCapacity::max(BookingType::Room, $guests))->toBe($guests->maxPerCabin);
    expect(GuestCapacity::max(BookingType::Charter, $guests))->toBe($guests->maxPerProperty);
    expect($guests->maxPerCabin)->not->toBe($guests->maxPerProperty);
});
