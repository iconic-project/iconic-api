<?php

declare(strict_types=1);

use App\Enums\CabinCategory;
use App\Models\Cabin;
use App\Models\Itinerary;
use App\Models\Property;
use Database\Seeders\InventorySeeder;

test('InventorySeeder gives two properties with nine cabins each and is idempotent', function (): void {
    $this->seed(InventorySeeder::class);
    $this->seed(InventorySeeder::class);

    expect(Property::query()->count())->toBe(2);
    expect(Cabin::query()->count())->toBe(18);

    foreach (['ANAMARA', 'ANATIVA'] as $code) {
        $property = Property::query()->where('code', $code)->firstOrFail();
        expect($property->name)->toBe($code);
        expect($property->cabins)->toHaveCount(9);
        expect($property->cabins->pluck('code')->all())->toBe([
            'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'OWNER',
        ]);
        expect($property->cabins->last()?->label)->toBe("Owner's Suite");
        expect($property->cabins->last()?->category)->toBe(CabinCategory::Owner);
        expect($property->cabins->first()?->label)->toBe('Suite 01');
        expect($property->cabins->first()?->category)->toBe(CabinCategory::Suite);
    }
});

test('a production-like seed does not create itineraries', function (): void {
    $this->seed(InventorySeeder::class);

    expect(Itinerary::query()->count())->toBe(0);
});
