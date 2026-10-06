<?php

declare(strict_types=1);

use App\Enums\BlockReason;
use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\RoomStatus;
use App\Enums\RoomTypeStatus;
use App\Models\ChangeHistory;
use App\Models\InternalBlock;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Support\Inventory\BackfillInternalBlockRanges;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('a sold night blocks the stay and names that night and holder', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create([
        'property_id' => $property->id,
        'code' => 'DLX',
        'name' => 'Deluxe',
        'status' => RoomTypeStatus::Active,
    ]);
    $room = Room::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'code' => '204',
        'label' => 'Room 204',
        'sort' => 1,
        'status' => RoomStatus::Active,
    ]);
    $holder = ClaimHolder::query()->create(['reference' => 'ANK-2028-0012', 'name' => 'Ada']);

    RoomNightClaim::query()->create([
        'room_id' => $room->id,
        'night' => '2028-03-04',
        'holder_type' => $holder->getMorphClass(),
        'holder_id' => $holder->id,
        'kind' => ClaimKind::Booking,
        'claim_group' => (string) Str::uuid(),
    ]);

    $response = $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Maintenance->value,
        'starts_on' => '2028-03-03',
        'ends_on' => '2028-03-06',
        'rooms' => [$room->id],
    ]);

    $response->assertConflict();
    expect($response->json('message'))->toBe('Deluxe is unavailable on 2028-03-03.');
    expect(InternalBlock::query()->count())->toBe(0);
    expect(RoomNightClaim::query()->where('kind', ClaimKind::Block)->count())->toBe(0);
});

test('an expired hold does not block the room', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'status' => RoomTypeStatus::Active]);
    $room = Room::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'label' => 'Room 101',
        'status' => RoomStatus::Active,
    ]);
    $holder = ClaimHolder::query()->create(['reference' => 'HLD-OLD', 'name' => 'Old']);

    RoomNightClaim::query()->create([
        'room_id' => $room->id,
        'night' => '2028-03-03',
        'holder_type' => $holder->getMorphClass(),
        'holder_id' => $holder->id,
        'kind' => ClaimKind::Hold,
        'hold_type' => HoldType::Agency,
        'expires_at' => now()->subHour(),
        'claim_group' => (string) Str::uuid(),
    ]);

    $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Maintenance->value,
        'starts_on' => '2028-03-03',
        'ends_on' => '2028-03-05',
        'rooms' => [$room->id],
    ])->assertCreated();
});

test('rooms on a date range are claimed and the list returns that range', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'status' => RoomTypeStatus::Active]);
    $first = Room::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'code' => '101',
        'label' => '101',
        'sort' => 1,
    ]);
    $second = Room::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'code' => '102',
        'label' => '102',
        'sort' => 2,
    ]);

    $response = $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Maintenance->value,
        'starts_on' => '2028-03-03',
        'ends_on' => '2028-03-06',
        'rooms' => [$second->id, $first->id],
    ])->assertCreated();

    $response->assertJsonPath('starts_on', '2028-03-03')
        ->assertJsonPath('ends_on', '2028-03-06')
        ->assertJsonPath('nights', 3)
        ->assertJsonPath('property.id', $property->id)
        ->assertJsonPath('scope_summary', 'Rooms 101, 102 · Fri 3 – Mon 6 Mar 2028 · 3 nights')
        ->assertJsonCount(2, 'rooms');

    expect($response->json())->not->toHaveKey('claims');
    expect(ChangeHistory::query()->where('event', 'block.created')->count())->toBe(1);

    $block = InternalBlock::query()->firstOrFail();
    expect($block->claims()->whereNull('released_at')->count())->toBe(6);

    $this->actingAs(managerUser())
        ->getJson('/api/rms/blocks?property_id='.$property->id.'&from=2028-03-01&to=2028-03-05')
        ->assertOk()
        ->assertJsonPath('data.0.id', $block->id);

    $this->actingAs(managerUser())
        ->getJson('/api/rms/blocks?from=2028-03-06')
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('a room type and a count claims that many rooms', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create([
        'property_id' => $property->id,
        'status' => RoomTypeStatus::Active,
    ]);

    foreach ([1, 2, 3] as $sort) {
        Room::factory()->create([
            'property_id' => $property->id,
            'room_type_id' => $type->id,
            'code' => 'R'.$sort,
            'label' => 'Room '.$sort,
            'sort' => $sort,
        ]);
    }

    $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::NegotiationHold->value,
        'starts_on' => '2028-03-03',
        'ends_on' => '2028-03-06',
        'room_type_id' => $type->id,
        'count' => 2,
    ])->assertCreated()
        ->assertJsonCount(2, 'rooms')
        ->assertJsonPath('rooms.0.label', 'Room 1')
        ->assertJsonPath('rooms.1.label', 'Room 2');
});

test('a block may run longer than the maximum stay', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'status' => RoomTypeStatus::Active]);
    $room = Room::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'status' => RoomStatus::Active,
    ]);

    $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Maintenance->value,
        'starts_on' => '2028-06-01',
        'ends_on' => '2028-07-11',
        'rooms' => [$room->id],
    ])->assertCreated()
        ->assertJsonPath('nights', 40);
});

test('a block cannot start in the past or mix properties', function (): void {
    $first = Property::factory()->create();
    $second = Property::factory()->create();
    $typeA = RoomType::factory()->create(['property_id' => $first->id, 'status' => RoomTypeStatus::Active]);
    $typeB = RoomType::factory()->create(['property_id' => $second->id, 'status' => RoomTypeStatus::Active]);
    $roomA = Room::factory()->create(['property_id' => $first->id, 'room_type_id' => $typeA->id]);
    $roomB = Room::factory()->create(['property_id' => $second->id, 'room_type_id' => $typeB->id]);

    $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Maintenance->value,
        'starts_on' => '2020-01-01',
        'ends_on' => '2020-01-03',
        'rooms' => [$roomA->id],
    ])->assertUnprocessable();

    $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Maintenance->value,
        'starts_on' => '2028-03-03',
        'ends_on' => '2028-03-05',
        'rooms' => [$roomA->id, $roomB->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('rooms');
});

test('release needs a note, frees the rooms, and a second release is 409', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'status' => RoomTypeStatus::Active]);
    $room = Room::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'status' => RoomStatus::Active,
    ]);
    $mateo = managerUser();

    $created = $this->actingAs($mateo)->postJson('/api/rms/blocks', [
        'reason' => BlockReason::FamTrip->value,
        'notes' => 'Agents',
        'starts_on' => '2028-03-03',
        'ends_on' => '2028-03-06',
        'rooms' => [$room->id],
    ])->assertCreated();

    $id = $created->json('id');

    $this->actingAs($mateo)
        ->postJson("/api/rms/blocks/{$id}/release", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('note');

    $this->actingAs($mateo)
        ->postJson("/api/rms/blocks/{$id}/release", ['note' => 'Done'])
        ->assertOk()
        ->assertJsonPath('release_note', 'Done');

    expect(RoomNightClaim::query()->where('holder_id', $id)->whereNull('released_at')->count())->toBe(0);
    expect(ChangeHistory::query()->where('event', 'block.released')->count())->toBe(1);

    $this->actingAs($mateo)
        ->getJson('/api/rms/blocks?status=released')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->actingAs($mateo)
        ->postJson("/api/rms/blocks/{$id}/release", ['note' => 'Again'])
        ->assertConflict()
        ->assertJsonPath('message', 'This block is already released.');
});

test('shorten drops the leading or trailing nights and writes one history row', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'status' => RoomTypeStatus::Active]);
    $room = Room::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'label' => '101',
        'status' => RoomStatus::Active,
    ]);
    $mateo = managerUser();

    $created = $this->actingAs($mateo)->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Maintenance->value,
        'starts_on' => '2028-03-03',
        'ends_on' => '2028-03-06',
        'rooms' => [$room->id],
    ])->assertCreated();

    $id = $created->json('id');

    $this->actingAs($mateo)
        ->postJson("/api/rms/blocks/{$id}/shorten", [
            'starts_on' => '2028-03-04',
            'ends_on' => '2028-03-05',
            'reason' => 'Both ends',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ends_on');

    $this->actingAs($mateo)
        ->postJson("/api/rms/blocks/{$id}/shorten", [
            'starts_on' => '2028-03-04',
            'ends_on' => '2028-03-06',
            'reason' => 'Maintenance starts later',
        ])
        ->assertOk()
        ->assertJsonPath('starts_on', '2028-03-04')
        ->assertJsonPath('ends_on', '2028-03-06')
        ->assertJsonPath('scope_summary', 'Room 101 · Sat 4 – Mon 6 Mar 2028 · 2 nights');

    expect(RoomNightClaim::query()->where('holder_id', $id)->whereNull('released_at')->orderBy('night')->pluck('night')->map->toDateString()->all())
        ->toBe(['2028-03-04', '2028-03-05']);

    $entry = ChangeHistory::query()->where('event', 'block.shortened')->firstOrFail();
    expect($entry->reason)->toBe('Maintenance starts later');
    expect($entry->before)->toMatchArray(['starts_on' => '2028-03-03', 'ends_on' => '2028-03-06']);
    expect($entry->after)->toMatchArray(['starts_on' => '2028-03-04', 'ends_on' => '2028-03-06']);
    expect(ChangeHistory::query()->where('event', 'block.shortened')->count())->toBe(1);

    $this->actingAs($mateo)
        ->postJson("/api/rms/blocks/{$id}/shorten", [
            'starts_on' => '2028-03-04',
            'ends_on' => '2028-03-05',
            'reason' => 'Ends sooner',
        ])
        ->assertOk()
        ->assertJsonPath('ends_on', '2028-03-05');

    expect(RoomNightClaim::query()->where('holder_id', $id)->whereNull('released_at')->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'block.shortened')->count())->toBe(2);
});

test('notes and reason can change and the range does not', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'status' => RoomTypeStatus::Active]);
    $room = Room::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'label' => '101',
    ]);
    $mateo = managerUser();

    $created = $this->actingAs($mateo)->postJson('/api/rms/blocks', [
        'reason' => BlockReason::FamTrip->value,
        'notes' => 'Before',
        'starts_on' => '2028-03-03',
        'ends_on' => '2028-03-06',
        'rooms' => [$room->id],
    ])->assertCreated();

    $id = $created->json('id');
    $summary = $created->json('scope_summary');

    $this->actingAs($mateo)
        ->patchJson("/api/rms/blocks/{$id}", [
            'reason' => BlockReason::Courtesy->value,
            'notes' => 'After',
        ])
        ->assertOk()
        ->assertJsonPath('reason', BlockReason::Courtesy->value)
        ->assertJsonPath('notes', 'After')
        ->assertJsonPath('scope_summary', $summary);

    expect(ChangeHistory::query()->where('event', 'block.updated')->count())->toBe(1);
    expect(RoomNightClaim::query()->where('holder_id', $id)->whereNull('released_at')->count())->toBe(3);
});

test('admin and a manager can write blocks and a sales exec cannot', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'status' => RoomTypeStatus::Active]);
    $room = Room::factory()->create([
        'property_id' => $property->id,
        'room_type_id' => $type->id,
        'status' => RoomStatus::Active,
    ]);
    $payload = [
        'reason' => BlockReason::Maintenance->value,
        'starts_on' => '2028-03-03',
        'ends_on' => '2028-03-05',
        'rooms' => [$room->id],
    ];

    $created = $this->actingAs(adminUser())->postJson('/api/rms/blocks', $payload)->assertCreated();
    $id = $created->json('id');
    $lucia = salesExecUser();

    $this->actingAs($lucia)->getJson('/api/rms/blocks')->assertOk();
    $this->actingAs($lucia)->getJson("/api/rms/blocks/{$id}/history")->assertOk();
    $this->actingAs($lucia)->postJson('/api/rms/blocks', $payload)->assertForbidden();
    $this->actingAs($lucia)->patchJson("/api/rms/blocks/{$id}", ['notes' => 'no'])->assertForbidden();
    $this->actingAs($lucia)->postJson("/api/rms/blocks/{$id}/release", ['note' => 'no'])->assertForbidden();
    $this->actingAs($lucia)->postJson("/api/rms/blocks/{$id}/shorten", [
        'starts_on' => '2028-03-04',
        'ends_on' => '2028-03-05',
        'reason' => 'no',
    ])->assertForbidden();

    $this->actingAs(managerUser())
        ->postJson("/api/rms/blocks/{$id}/shorten", [
            'starts_on' => '2028-03-04',
            'ends_on' => '2028-03-05',
            'reason' => 'Later start',
        ])
        ->assertOk();
});

test('blocks cannot be deleted', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'status' => RoomTypeStatus::Active]);
    $room = Room::factory()->create(['property_id' => $property->id, 'room_type_id' => $type->id]);

    $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Courtesy->value,
        'starts_on' => '2028-03-03',
        'ends_on' => '2028-03-05',
        'rooms' => [$room->id],
    ])->assertCreated();

    $block = InternalBlock::query()->firstOrFail();

    expect(fn () => $block->delete())->toThrow(LogicException::class, 'Internal blocks cannot be deleted.');
});

test('backfill copies the claim span onto the block', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::factory()->create(['property_id' => $property->id, 'status' => RoomTypeStatus::Active]);
    $room = Room::factory()->create(['property_id' => $property->id, 'room_type_id' => $type->id]);

    $created = $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Courtesy->value,
        'starts_on' => '2028-03-03',
        'ends_on' => '2028-03-06',
        'rooms' => [$room->id],
    ])->assertCreated();

    $block = InternalBlock::query()->findOrFail($created->json('id'));
    $block->starts_on = '2028-01-01';
    $block->ends_on = '2028-01-02';
    $block->save();

    BackfillInternalBlockRanges::run();
    $block->refresh();

    expect($block->property_id)->toBe($property->id);
    expect($block->starts_on->toDateString())->toBe('2028-03-03');
    expect($block->ends_on->toDateString())->toBe('2028-03-06');
});
