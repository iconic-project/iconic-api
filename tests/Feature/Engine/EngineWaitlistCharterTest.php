<?php

declare(strict_types=1);

use App\Enums\WaitlistSource;
use App\Models\RoomType;
use App\Models\WaitlistEntry;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\HotelSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function engineWaitlistPayload(string $roomType = 'STD', array $overrides = []): array
{
    return array_merge([
        'room_type' => $roomType,
        'check_in' => '2026-12-21',
        'check_out' => '2026-12-24',
        'contact' => [
            'name' => 'Wait Guest',
            'email' => 'wait-'.uniqid().'@iconic.test',
        ],
        'adults' => 2,
        'children' => 0,
        'notes' => 'Any suite',
    ], $overrides);
}

test('the engine waitlist is stored with source engine and refused when off', function (): void {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(HotelSeeder::class);
    $this->postJson('/api/engine/waitlist', engineWaitlistPayload())
        ->assertCreated()
        ->assertJsonPath('source', WaitlistSource::Engine->value)
        ->assertJsonPath('room_type', 'STD')
        ->assertJsonPath('check_in', '2026-12-21');

    expect(WaitlistEntry::query()->value('source'))->toBe(WaitlistSource::Engine);

    RoomType::query()->where('code', 'STD')->update(['waitlist_enabled' => false]);

    $this->postJson('/api/engine/waitlist', engineWaitlistPayload())
        ->assertUnprocessable()
        ->assertJsonPath('errors.room_type.0', 'The waitlist is off for this room type.');
});

test('a room type that is not on the published property cannot be waitlisted', function (): void {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(HotelSeeder::class);
    $this->postJson('/api/engine/waitlist', engineWaitlistPayload('HIDDEN'))->assertNotFound();
});

test('the engine waitlist is rate limited', function (): void {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(HotelSeeder::class);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/engine/waitlist', engineWaitlistPayload())->assertCreated();
    }

    $this->postJson('/api/engine/waitlist', engineWaitlistPayload())->assertStatus(429);
});
