<?php

declare(strict_types=1);

use App\Models\Itinerary;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Config\Documents\EngineSettingsDocument;
use Database\Seeders\InventorySeeder;

test('InventorySeeder gives two properties with nine cabins each and is idempotent', function (): void {
    $this->seed(InventorySeeder::class);
    $this->seed(InventorySeeder::class);

    expect(Property::query()->count())->toBe(2);
    expect(Room::query()->count())->toBe(18);

    $maxPerCabin = (int) EngineSettingsDocument::initial()['guests']['max_per_cabin'];

    foreach (['ANAMARA', 'ANATIVA'] as $code) {
        $property = Property::query()->where('code', $code)->firstOrFail();
        expect($property->name)->toBe($code);
        expect($property->cabins)->toHaveCount(9);
        expect($property->cabins->pluck('code')->all())->toBe([
            'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'OWNER',
        ]);
        expect($property->cabins->last()?->label)->toBe("Owner's Suite");
        expect($property->cabins->last()?->roomType->code)->toBe('OWNER');
        expect($property->cabins->last()?->roomType->name)->toBe("Owner's Suite");
        expect($property->cabins->first()?->label)->toBe('Suite 01');
        expect($property->cabins->first()?->roomType->code)->toBe('SUITE');
        expect($property->roomTypes)->toHaveCount(2);
        expect($property->cabins->every(fn (Room $room): bool => $room->room_type_id !== null))->toBeTrue();
        expect($property->roomTypes->every(
            fn (RoomType $type): bool => $type->max_occupancy === $maxPerCabin
                && $type->base_occupancy === $maxPerCabin
                && $type->max_adults === $maxPerCabin
                && $type->max_children === $maxPerCabin,
        ))->toBeTrue();
        expect($property->roomTypes->firstWhere('code', 'SUITE')?->rooms)->toHaveCount(8);
        expect($property->roomTypes->firstWhere('code', 'OWNER')?->rooms)->toHaveCount(1);
    }
});

test('a production-like seed does not create itineraries', function (): void {
    $this->seed(InventorySeeder::class);

    expect(Itinerary::query()->count())->toBe(0);
});
