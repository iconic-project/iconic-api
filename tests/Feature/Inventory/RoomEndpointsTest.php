<?php

declare(strict_types=1);

use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ItineraryStatus;
use App\Models\CabinClaim;
use App\Models\ChangeHistory;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
});

test('a sales exec can list room types and rooms', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $sales = salesExecUser();

    $types = $this->actingAs($sales)->getJson("/api/rms/properties/{$property->id}/room-types");
    $types->assertOk();
    expect(array_column($types->json('data'), 'code'))->toBe(['SUITE', 'OWNER']);
    expect($types->json('data.0.waitlist_enabled'))->toBeTrue();
    expect($types->json('data.0.status'))->toBe('ACTIVE');

    $rooms = $this->actingAs($sales)->getJson("/api/rms/properties/{$property->id}/rooms");
    $rooms->assertOk();
    expect($rooms->json('data'))->toHaveCount(9);
    expect($rooms->json('data.8.room_type.code'))->toBe('OWNER');
    expect($rooms->json('data.8.status'))->toBe('ACTIVE');
});

test('a user without panel.rms cannot list room types or rooms', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $user = User::factory()->create();

    $this->actingAs($user)->getJson("/api/rms/properties/{$property->id}/room-types")->assertForbidden();
    $this->actingAs($user)->getJson("/api/rms/properties/{$property->id}/rooms")->assertForbidden();
});

test('an admin can create and update a room type', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

    $created = $this->actingAs(adminUser())->postJson("/api/rms/properties/{$property->id}/room-types", [
        'code' => 'DBL',
        'name' => 'Double',
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'max_adults' => 2,
        'max_children' => 1,
        'slug' => 'anamara-double',
        'meta_title' => 'Double',
        'meta_description' => 'A double room.',
        'amenities' => ['Desk'],
        'photos' => [['path' => 'rooms/double.jpg', 'alt' => 'Double']],
    ]);

    $created->assertCreated();
    $created->assertJsonPath('code', 'DBL');
    $created->assertJsonPath('waitlist_enabled', true);
    $created->assertJsonPath('status', 'ACTIVE');

    $typeId = $created->json('id');

    $this->actingAs(adminUser())
        ->patchJson("/api/rms/room-types/{$typeId}", [
            'name' => 'Double room',
            'bed_setup' => 'King',
        ])
        ->assertOk()
        ->assertJsonPath('name', 'Double room')
        ->assertJsonPath('bed_setup', 'King')
        ->assertJsonPath('code', 'DBL');

    expect(ChangeHistory::query()->where('subject_type', 'room_type')->where('subject_id', $typeId)->pluck('event')->all())
        ->toBe(['room_type.created', 'room_type.updated']);
});

test('a room type update that changes nothing writes no history', function (): void {
    $type = RoomType::query()->where('code', 'SUITE')->firstOrFail();

    $this->actingAs(adminUser())
        ->patchJson("/api/rms/room-types/{$type->id}", ['name' => $type->name])
        ->assertOk();

    expect(ChangeHistory::query()->where('event', 'room_type.updated')->count())->toBe(0);
});

test('manager and sales cannot create or update room types', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $type = RoomType::query()->where('property_id', $property->id)->where('code', 'SUITE')->firstOrFail();
    $payload = [
        'code' => 'DBL',
        'name' => 'Double',
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'max_adults' => 2,
        'max_children' => 1,
    ];

    foreach ([managerUser(), salesExecUser()] as $user) {
        $this->actingAs($user)->postJson("/api/rms/properties/{$property->id}/room-types", $payload)->assertForbidden();
        $this->actingAs($user)->patchJson("/api/rms/room-types/{$type->id}", ['name' => 'Nope'])->assertForbidden();
    }
});

test('room type occupancy, slug and meta limits are rejected', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $admin = adminUser();

    $this->actingAs($admin)->postJson("/api/rms/properties/{$property->id}/room-types", [
        'code' => 'BAD',
        'name' => 'Bad',
        'base_occupancy' => 4,
        'max_occupancy' => 2,
        'max_adults' => 3,
        'max_children' => 3,
        'slug' => 'Not Slug',
        'meta_title' => str_repeat('a', 61),
        'meta_description' => str_repeat('b', 156),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['base_occupancy', 'max_adults', 'max_children', 'slug', 'meta_title', 'meta_description']);

    $type = RoomType::query()->where('property_id', $property->id)->where('code', 'SUITE')->firstOrFail();
    $this->actingAs($admin)->patchJson("/api/rms/room-types/{$type->id}", [
        'max_occupancy' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors(['base_occupancy', 'max_adults', 'max_children']);

    $this->actingAs($admin)->postJson("/api/rms/properties/{$property->id}/room-types", [
        'code' => 'SUITE',
        'name' => 'Suite again',
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'max_adults' => 2,
        'max_children' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
});

test('deactivating a room type refuses while a future claim exists', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $type = RoomType::query()->where('property_id', $property->id)->where('code', 'SUITE')->firstOrFail();
    $room = $type->rooms()->firstOrFail();
    $departure = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
        'date' => '2028-04-02',
    ]);
    $holder = ClaimHolder::query()->create(['reference' => 'CLM-001', 'name' => 'CLM-001']);

    CabinClaim::query()->create([
        'departure_id' => $departure->id,
        'room_id' => $room->id,
        'holder_type' => 'claim_holder',
        'holder_id' => $holder->id,
        'kind' => ClaimKind::Booking,
        'hold_type' => null,
        'expires_at' => null,
        'released_at' => null,
    ]);

    $this->actingAs(adminUser())
        ->postJson("/api/rms/room-types/{$type->id}/deactivate")
        ->assertStatus(409);

    expect($type->fresh()?->status->value)->toBe('ACTIVE');
});

test('an admin can deactivate a room type with no future claim', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $created = $this->actingAs(adminUser())->postJson("/api/rms/properties/{$property->id}/room-types", [
        'code' => 'SGL',
        'name' => 'Single',
        'base_occupancy' => 1,
        'max_occupancy' => 1,
        'max_adults' => 1,
        'max_children' => 0,
    ])->assertCreated();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/room-types/'.$created->json('id').'/deactivate')
        ->assertOk()
        ->assertJsonPath('status', 'INACTIVE');

    expect(ChangeHistory::query()->where('event', 'room_type.deactivated')->count())->toBe(1);
});

test('an admin can create, update and deactivate a room', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $type = RoomType::query()->where('property_id', $property->id)->where('code', 'SUITE')->firstOrFail();

    $created = $this->actingAs(adminUser())->postJson("/api/rms/properties/{$property->id}/rooms", [
        'code' => 'S10',
        'label' => 'Suite 10',
        'floor' => '2',
        'room_type_id' => $type->id,
        'sort' => 10,
    ]);

    $created->assertCreated();
    $created->assertJsonPath('code', 'S10');
    $created->assertJsonPath('floor', '2');
    $created->assertJsonPath('room_type.code', 'SUITE');
    $created->assertJsonPath('status', 'ACTIVE');

    $roomId = $created->json('id');

    $this->actingAs(adminUser())
        ->patchJson("/api/rms/rooms/{$roomId}", ['label' => 'Suite ten', 'floor' => '3'])
        ->assertOk()
        ->assertJsonPath('label', 'Suite ten')
        ->assertJsonPath('floor', '3');

    $this->actingAs(adminUser())
        ->postJson("/api/rms/rooms/{$roomId}/deactivate")
        ->assertOk()
        ->assertJsonPath('status', 'INACTIVE');

    expect(ChangeHistory::query()->where('subject_type', 'room')->where('subject_id', $roomId)->pluck('event')->all())
        ->toBe(['room.created', 'room.updated', 'room.deactivated']);
});

test('deactivating a room refuses while a future claim exists', function (): void {
    $room = Room::query()->where('code', 'S1')->firstOrFail();
    $departure = Departure::factory()->create([
        'property_id' => $room->property_id,
        'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
        'date' => '2028-04-02',
    ]);
    $holder = ClaimHolder::query()->create(['reference' => 'CLM-002', 'name' => 'CLM-002']);

    CabinClaim::query()->create([
        'departure_id' => $departure->id,
        'room_id' => $room->id,
        'holder_type' => 'claim_holder',
        'holder_id' => $holder->id,
        'kind' => ClaimKind::Hold,
        'hold_type' => HoldType::Web,
        'expires_at' => now()->addHour(),
        'released_at' => null,
    ]);

    $this->actingAs(adminUser())
        ->postJson("/api/rms/rooms/{$room->id}/deactivate")
        ->assertStatus(409);
});

test('sales cannot create a room', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $type = RoomType::query()->where('property_id', $property->id)->where('code', 'SUITE')->firstOrFail();

    $this->actingAs(salesExecUser())->postJson("/api/rms/properties/{$property->id}/rooms", [
        'code' => 'S11',
        'label' => 'Suite 11',
        'room_type_id' => $type->id,
    ])->assertForbidden();
});
