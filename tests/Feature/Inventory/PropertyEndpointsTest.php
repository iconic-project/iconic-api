<?php

declare(strict_types=1);

use App\Enums\PropertyStatus;
use App\Models\ChangeHistory;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
});

test('a sales exec can list properties with cabins in sort order', function (): void {
    $sales = salesExecUser();

    $response = $this->actingAs($sales)->getJson('/api/rms/properties');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(2);
    expect(array_column($data, 'code'))->toBe(['ANAMARA', 'ANATIVA']);
    expect($data[0]['rooms'])->toHaveCount(9);
    expect(array_column($data[0]['rooms'], 'code'))->toBe([
        'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'OWNER',
    ]);
    expect($data[0]['rooms'][8]['label'])->toBe("Owner's Suite");
    expect($data[0]['rooms'][8]['room_type']['code'])->toBe('OWNER');
    expect($data[0]['status'])->toBe('ACTIVE');
});

test('a user without panel.rms cannot list properties', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/rms/properties')->assertForbidden();
});

test('a sales exec can show one property', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

    $this->actingAs(salesExecUser())
        ->getJson("/api/rms/properties/{$property->id}")
        ->assertOk()
        ->assertJsonPath('code', 'ANAMARA')
        ->assertJsonPath('status', 'ACTIVE')
        ->assertJsonPath('slug', null);
});

test('a user without panel.rms cannot show a property', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

    $this->actingAs(User::factory()->create())
        ->getJson("/api/rms/properties/{$property->id}")
        ->assertForbidden();
});

test('an admin can update property content', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

    $this->actingAs(adminUser())
        ->patchJson("/api/rms/properties/{$property->id}", [
            'name' => 'Anamara House',
            'slug' => 'anamara',
            'city' => 'Puerto Baquerizo Moreno',
            'country' => 'EC',
            'meta_title' => 'Anamara',
            'meta_description' => 'A house on San Cristóbal.',
            'highlights' => ['Sundeck'],
            'status' => 'ACTIVE',
        ])
        ->assertOk()
        ->assertJsonPath('name', 'Anamara House')
        ->assertJsonPath('slug', 'anamara')
        ->assertJsonPath('city', 'Puerto Baquerizo Moreno')
        ->assertJsonPath('country', 'EC')
        ->assertJsonPath('code', 'ANAMARA');

    expect($property->fresh()->status)->toBe(PropertyStatus::Active);
});

test('a manager cannot update a property', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

    $this->actingAs(managerUser())
        ->patchJson("/api/rms/properties/{$property->id}", ['name' => 'Nope'])
        ->assertForbidden();
});

test('a sales exec cannot update a property', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

    $this->actingAs(salesExecUser())
        ->patchJson("/api/rms/properties/{$property->id}", ['name' => 'Nope'])
        ->assertForbidden();
});

test('a user without panel.rms cannot update a property', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

    $this->actingAs(User::factory()->create())
        ->patchJson("/api/rms/properties/{$property->id}", ['name' => 'Nope'])
        ->assertForbidden();
});

test('slug must be unique and meta fields stay within their limits', function (): void {
    $first = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $second = Property::query()->where('code', 'ANATIVA')->firstOrFail();
    $admin = adminUser();

    $this->actingAs($admin)
        ->patchJson("/api/rms/properties/{$first->id}", ['slug' => 'anamara'])
        ->assertOk();

    $this->actingAs($admin)
        ->patchJson("/api/rms/properties/{$second->id}", [
            'slug' => 'anamara',
            'meta_title' => str_repeat('a', 61),
            'meta_description' => str_repeat('b', 156),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slug', 'meta_title', 'meta_description']);
});

test('a content change writes property.updated once', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

    $this->actingAs(adminUser())
        ->patchJson("/api/rms/properties/{$property->id}", ['name' => 'Anamara House'])
        ->assertOk();

    $events = ChangeHistory::query()
        ->where('subject_type', 'property')
        ->where('subject_id', $property->id)
        ->pluck('event')
        ->all();

    expect($events)->toBe(['property.updated']);
});

test('a no-op patch writes no history', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

    $this->actingAs(adminUser())
        ->patchJson("/api/rms/properties/{$property->id}", ['name' => $property->name])
        ->assertOk();

    expect(ChangeHistory::query()->where('event', 'property.updated')->count())->toBe(0);
});

test('seeded inventory keeps every room on a property', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

    expect(Schema::hasTable('properties'))->toBeTrue();
    expect(Schema::hasTable('rooms'))->toBeTrue();
    expect(Schema::hasTable('departures'))->toBeFalse();
    expect(Schema::hasTable('archive_departures'))->toBeTrue();
    expect(Schema::hasColumn('rooms', 'property_id'))->toBeTrue();
    expect(Schema::hasColumn('rooms', 'room_type_id'))->toBeTrue();
    expect(Property::query()->count())->toBe(2);
    expect(Room::query()->count())->toBe(18);
    expect(Room::query()->whereDoesntHave('property')->exists())->toBeFalse();
    expect(Room::query()->where('property_id', $property->id)->count())->toBe(9);
});
