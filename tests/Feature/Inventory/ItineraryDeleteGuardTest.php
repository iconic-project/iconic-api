<?php

declare(strict_types=1);

use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
});

test('delete is refused for an unused itinerary and for one a departure uses', function (): void {
    $unused = Itinerary::factory()->create();
    $used = Itinerary::factory()->create();
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $used->id,
        'date' => '2028-04-02',
    ]);
    $admin = adminUser();

    $this->actingAs($admin)->deleteJson("/api/rms/itineraries/{$unused->id}")->assertForbidden();
    $this->actingAs($admin)->deleteJson("/api/rms/itineraries/{$used->id}")->assertForbidden();

    expect(Itinerary::query()->whereKey($unused->id)->exists())->toBeTrue();
    expect(Itinerary::query()->whereKey($used->id)->exists())->toBeTrue();
});
