<?php

declare(strict_types=1);

use App\Models\ChangeHistory;
use App\Models\Itinerary;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
});

test('itinerary writes are refused and write no history', function (): void {
    $itinerary = Itinerary::factory()->publishable()->create(['name' => 'Old']);
    $admin = adminUser();

    $this->actingAs($admin)
        ->patchJson("/api/rms/itineraries/{$itinerary->id}", ['name' => 'New name'])
        ->assertForbidden();

    $this->actingAs($admin)
        ->patchJson("/api/rms/itineraries/{$itinerary->id}", ['status' => 'PUBLISHED'])
        ->assertForbidden();

    $this->actingAs($admin)
        ->patchJson("/api/rms/itineraries/{$itinerary->id}", ['status' => 'HIDDEN'])
        ->assertForbidden();

    expect($itinerary->fresh()?->name)->toBe('Old');
    expect(ChangeHistory::query()->where('subject_type', 'itinerary')->where('subject_id', $itinerary->id)->count())->toBe(0);
});
