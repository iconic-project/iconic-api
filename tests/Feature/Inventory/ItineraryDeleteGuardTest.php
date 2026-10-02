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

test('delete succeeds when no departure uses the itinerary', function (): void {
    $itinerary = Itinerary::factory()->create();
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->deleteJson("/api/rms/itineraries/{$itinerary->id}")
        ->assertNoContent();
});

test('delete is refused while one departure uses the itinerary', function (): void {
    $itinerary = Itinerary::factory()->create();
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-02',
    ]);
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->deleteJson("/api/rms/itineraries/{$itinerary->id}")
        ->assertConflict()
        ->assertJsonPath('message', 'Used by 1 departure');
});

test('delete is refused while several departures use the itinerary', function (): void {
    $itinerary = Itinerary::factory()->create();
    $anamara = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $anativa = Property::query()->where('code', 'ANATIVA')->firstOrFail();

    Departure::factory()->create([
        'property_id' => $anamara->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-02',
    ]);
    Departure::factory()->create([
        'property_id' => $anativa->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-02',
    ]);
    Departure::factory()->create([
        'property_id' => $anamara->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-09',
    ]);

    $mateo = managerUser();

    $this->actingAs($mateo)
        ->deleteJson("/api/rms/itineraries/{$itinerary->id}")
        ->assertConflict()
        ->assertJsonPath('message', 'Used by 3 departures');
});
