<?php

declare(strict_types=1);

use App\Enums\DepartureStatus;
use App\Models\ChangeHistory;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
});

test('a content-only patch writes departure.updated', function (): void {
    $itinerary = Itinerary::factory()->create();
    $departure = Departure::factory()->create([
        'property_id' => Property::query()->where('code', 'ANAMARA')->value('id'),
        'itinerary_id' => $itinerary->id,
        'public_note' => 'Old',
    ]);
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->patchJson("/api/rms/departures/{$departure->id}", ['public_note' => 'New note'])
        ->assertOk();

    $events = ChangeHistory::query()
        ->where('subject_type', 'departure')
        ->where('subject_id', $departure->id)
        ->where('event', '!=', 'departure.created')
        ->orderBy('id')
        ->pluck('event')
        ->all();

    expect($events)->toBe(['departure.updated']);
});

test('a status-only patch writes departure.status_changed', function (): void {
    $itinerary = Itinerary::factory()->create();
    $departure = Departure::factory()->create([
        'property_id' => Property::query()->where('code', 'ANAMARA')->value('id'),
        'itinerary_id' => $itinerary->id,
        'status' => DepartureStatus::OnSale,
    ]);
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->patchJson("/api/rms/departures/{$departure->id}", ['status' => 'CLOSED'])
        ->assertOk();

    $events = ChangeHistory::query()
        ->where('subject_id', $departure->id)
        ->whereIn('event', ['departure.updated', 'departure.status_changed'])
        ->orderBy('id')
        ->pluck('event')
        ->all();

    expect($events)->toBe(['departure.status_changed']);
});

test('a patch that changes content and status writes two history entries', function (): void {
    $itinerary = Itinerary::factory()->create();
    $departure = Departure::factory()->create([
        'property_id' => Property::query()->where('code', 'ANAMARA')->value('id'),
        'itinerary_id' => $itinerary->id,
        'public_note' => 'Old',
        'status' => DepartureStatus::OnSale,
    ]);
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->patchJson("/api/rms/departures/{$departure->id}", [
            'public_note' => 'New',
            'status' => 'CLOSED',
        ])
        ->assertOk();

    $entries = ChangeHistory::query()
        ->where('subject_type', 'departure')
        ->where('subject_id', $departure->id)
        ->whereIn('event', ['departure.updated', 'departure.status_changed'])
        ->orderBy('id')
        ->get();

    expect($entries)->toHaveCount(2);
    expect($entries[0]->event)->toBe('departure.updated');
    expect($entries[0]->before)->toHaveKey('public_note');
    expect($entries[0]->before)->not->toHaveKey('status');
    expect($entries[1]->event)->toBe('departure.status_changed');
});

test('a no-op patch writes no history', function (): void {
    $itinerary = Itinerary::factory()->create();
    $departure = Departure::factory()->create([
        'property_id' => Property::query()->where('code', 'ANAMARA')->value('id'),
        'itinerary_id' => $itinerary->id,
        'public_note' => 'Same',
    ]);
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->patchJson("/api/rms/departures/{$departure->id}", ['public_note' => 'Same'])
        ->assertOk();

    expect(ChangeHistory::query()->where('event', 'departure.updated')->count())->toBe(0);
});

test('departure history is listed', function (): void {
    $itinerary = Itinerary::factory()->create();
    $departure = Departure::factory()->create([
        'property_id' => Property::query()->where('code', 'ANAMARA')->value('id'),
        'itinerary_id' => $itinerary->id,
    ]);
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->getJson("/api/rms/departures/{$departure->id}/history")
        ->assertOk();
});
