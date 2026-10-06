<?php

declare(strict_types=1);

use App\Enums\RoomTypeStatus;
use App\Models\ChangeHistory;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Content\Completeness;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    Storage::fake('public');
});

test('property completeness counts hero, description, address, policies and meta', function (): void {
    $property = Property::factory()->create([
        'hero_image_path' => 'properties/hero.jpg',
        'hero_alt' => 'Front door',
        'description' => 'A house.',
        'address_line_1' => '1 Demo Street',
        'policies_text' => 'Quiet hours.',
        'meta_title' => 'House',
        'meta_description' => 'A house by the water.',
    ]);

    $complete = Completeness::forProperty($property);

    expect($complete->pct)->toBe(100);
    expect($complete->missing)->toBe([]);
    expect($complete->blocking)->toBe([]);

    $property->hero_alt = '';
    $property->policies_text = null;

    $gaps = Completeness::forProperty($property);

    expect($gaps->missing)->toBe(['hero photo', 'policies']);
    expect($gaps->blocking)->toBe(['hero photo', 'policies']);
    expect($gaps->pct)->toBe((int) round((4 / 6) * 100));
});

test('a room type without a photo or meta is not shown on the engine', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::query()->create([
        'property_id' => $property->id,
        'code' => 'DBL',
        'name' => 'Double',
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'max_adults' => 2,
        'max_children' => 0,
        'waitlist_enabled' => true,
        'sort' => 1,
        'status' => RoomTypeStatus::Active,
        'description' => 'A double.',
        'bed_setup' => '1 double bed',
        'meta_title' => null,
        'meta_description' => null,
        'photos' => [['path' => 'room-types/double.jpg', 'alt' => '']],
    ]);

    $gaps = Completeness::forRoomType($type);

    expect($gaps->missing)->toBe(['photo', 'SEO title', 'SEO description']);
    expect($gaps->blocking)->toBe($gaps->missing);
    expect(Completeness::engineVisible($type))->toBeFalse();

    $type->photos = [['path' => 'room-types/double.jpg', 'alt' => 'Double room']];
    $type->meta_title = 'Double';
    $type->meta_description = 'A double room.';

    expect(Completeness::forRoomType($type)->pct)->toBe(100);
    expect(Completeness::engineVisible($type))->toBeTrue();

    $type->status = RoomTypeStatus::Inactive;

    expect(Completeness::engineVisible($type))->toBeFalse();
});

test('an admin can replace a property hero and a manager cannot', function (): void {
    $property = Property::factory()->create();
    Storage::disk('public')->put('properties/old.jpg', 'old-bytes');
    $property->forceFill(['hero_image_path' => 'properties/old.jpg', 'hero_alt' => 'Old'])->save();

    $this->actingAs(managerUser())
        ->post("/api/rms/properties/{$property->id}/hero", [
            'image' => UploadedFile::fake()->image('hero.jpg'),
            'alt' => 'New front',
        ], ['Accept' => 'application/json'])
        ->assertForbidden();

    $url = $this->actingAs(adminUser())
        ->post("/api/rms/properties/{$property->id}/hero", [
            'image' => UploadedFile::fake()->image('hero.jpg'),
            'alt' => 'New front',
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('hero_image_url');

    expect($url)->toBeString()->toContain('/storage/properties/');

    $property->refresh();
    expect($property->hero_alt)->toBe('New front');
    Storage::disk('public')->assertMissing('properties/old.jpg');
    Storage::disk('public')->assertExists((string) $property->hero_image_path);
    expect(ChangeHistory::query()->where('event', 'property.hero_replaced')->count())->toBe(1);
    expect($property->fresh()?->hero_image_path)->not->toBe('properties/old.jpg');
});

test('property hero upload rejects a missing alt, a pdf and a file over 4mb', function (): void {
    $property = Property::factory()->create();
    $admin = adminUser();

    $this->actingAs($admin)
        ->post("/api/rms/properties/{$property->id}/hero", [
            'image' => UploadedFile::fake()->image('hero.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alt']);

    $this->actingAs($admin)
        ->post("/api/rms/properties/{$property->id}/hero", [
            'image' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
            'alt' => 'Notes',
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);

    $this->actingAs($admin)
        ->post("/api/rms/properties/{$property->id}/hero", [
            'image' => UploadedFile::fake()->image('huge.jpg')->size(5000),
            'alt' => 'Huge',
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image']);
});

test('a content patch cannot set the hero path', function (): void {
    $property = Property::factory()->create(['hero_image_path' => null]);

    $this->actingAs(adminUser())
        ->patchJson("/api/rms/properties/{$property->id}", [
            'hero_image_path' => 'properties/typed.jpg',
            'description' => 'Kept.',
        ])
        ->assertOk()
        ->assertJsonPath('description', 'Kept.')
        ->assertJsonPath('hero_image_path', null);
});

test('an admin can add a room type photo and cannot invent a path', function (): void {
    $property = Property::factory()->create();
    $type = RoomType::query()->create([
        'property_id' => $property->id,
        'code' => 'DBL',
        'name' => 'Double',
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'max_adults' => 2,
        'max_children' => 0,
        'waitlist_enabled' => true,
        'sort' => 1,
        'status' => RoomTypeStatus::Active,
        'photos' => [],
    ]);

    $this->actingAs(salesExecUser())
        ->post("/api/rms/room-types/{$type->id}/photos", [
            'image' => UploadedFile::fake()->image('room.jpg'),
            'alt' => 'The room',
        ], ['Accept' => 'application/json'])
        ->assertForbidden();

    $this->actingAs(adminUser())
        ->post("/api/rms/room-types/{$type->id}/photos", [
            'image' => UploadedFile::fake()->image('room.jpg'),
            'alt' => 'The room',
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('photos.0.alt', 'The room');

    $type->refresh();
    $path = $type->photos[0]['path'] ?? null;
    expect($path)->toBeString();
    Storage::disk('public')->assertExists((string) $path);

    $this->actingAs(adminUser())
        ->patchJson("/api/rms/room-types/{$type->id}", [
            'photos' => [['path' => 'room-types/typed.jpg', 'alt' => 'Typed']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photos']);

    Storage::disk('public')->put((string) $path, 'bytes');

    $this->actingAs(adminUser())
        ->patchJson("/api/rms/room-types/{$type->id}", [
            'photos' => [],
        ])
        ->assertOk()
        ->assertJsonPath('photos', []);

    Storage::disk('public')->assertMissing((string) $path);
    expect(Completeness::engineVisible($type->fresh() ?? $type))->toBeFalse();
});

test('property and room history are readable by a sales exec and hidden without panel access', function (): void {
    $this->seed(InventorySeeder::class);
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $type = RoomType::query()->where('property_id', $property->id)->where('code', 'SUITE')->firstOrFail();
    $room = Room::query()->where('property_id', $property->id)->where('code', 'S1')->firstOrFail();

    $this->actingAs(adminUser())
        ->patchJson("/api/rms/properties/{$property->id}", ['description' => 'A note.'])
        ->assertOk();

    $this->actingAs(salesExecUser())
        ->getJson("/api/rms/properties/{$property->id}/history")
        ->assertOk()
        ->assertJsonPath('data.0.event', 'property.updated');

    $this->actingAs(salesExecUser())
        ->getJson("/api/rms/room-types/{$type->id}/history")
        ->assertOk();

    $this->actingAs(salesExecUser())
        ->getJson("/api/rms/rooms/{$room->id}/history")
        ->assertOk();

    $outsider = User::factory()->create();

    $this->actingAs($outsider)->getJson("/api/rms/properties/{$property->id}/history")->assertForbidden();
    $this->actingAs($outsider)->getJson("/api/rms/room-types/{$type->id}/history")->assertForbidden();
    $this->actingAs($outsider)->getJson("/api/rms/rooms/{$room->id}/history")->assertForbidden();
});
