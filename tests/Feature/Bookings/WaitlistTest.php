<?php

declare(strict_types=1);

use App\Enums\CabinCategory;
use App\Enums\ClaimKind;
use App\Enums\ReleaseReason;
use App\Models\ChangeHistory;
use App\Models\Departure;
use App\Models\WaitlistEntry;
use App\Services\Inventory\ClaimService;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

/**
 * @return array<string, mixed>
 */
function waitlistPayload(Departure $departure, array $overrides = []): array
{
    return array_merge([
        'departure_id' => $departure->id,
        'cabin_category' => CabinCategory::Suite->value,
        'client' => [
            'name' => 'Anna Whitfield',
            'email' => 'anna-'.uniqid().'@iconic.test',
        ],
        'adults' => 2,
        'children' => 0,
    ], $overrides);
}

test('a waitlist entry is added and refused when the waitlist is off', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/waitlist', waitlistPayload($departure))
        ->assertCreated()
        ->assertJsonPath('cabin_category', 'SUITE')
        ->assertJsonPath('position', 1);

    expect(ChangeHistory::query()->where('event', 'waitlist.added')->count())->toBe(1);

    $closed = ReservationFixtures::anamaraDeparture('2027-11-14');
    $closed->update(['waitlist_enabled' => false]);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/waitlist', waitlistPayload($closed))
        ->assertUnprocessable()
        ->assertJsonPath('errors.departure_id.0', 'The waitlist is off for this departure.');
});

test('positions compact after a removal and cabin_available flips when a block is released', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $actor = managerUser();

    $first = $this->actingAs($actor)
        ->postJson('/api/rms/waitlist', waitlistPayload($departure, [
            'client' => ['name' => 'First', 'email' => 'first@iconic.test'],
        ]))
        ->assertCreated()
        ->json();

    $second = $this->actingAs($actor)
        ->postJson('/api/rms/waitlist', waitlistPayload($departure, [
            'client' => ['name' => 'Second', 'email' => 'second@iconic.test'],
        ]))
        ->assertCreated()
        ->json();

    $this->actingAs($actor)
        ->getJson('/api/rms/waitlist')
        ->assertOk()
        ->assertJsonPath('data.0.position', 1)
        ->assertJsonPath('data.1.position', 2);

    $this->actingAs($actor)
        ->postJson('/api/rms/waitlist/'.$first['id'].'/remove', ['reason' => 'No longer interested'])
        ->assertOk();

    expect(ChangeHistory::query()->where('event', 'waitlist.removed')->count())->toBe(1);

    $this->actingAs($actor)
        ->getJson('/api/rms/waitlist')
        ->assertOk()
        ->assertJsonPath('data.0.id', $second['id'])
        ->assertJsonPath('data.0.position', 1);

    $this->actingAs($actor)
        ->getJson('/api/rms/waitlist?include_removed=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $owner = $departure->yacht->cabins->firstWhere('code', 'OWNER');
    $holder = ClaimHolder::query()->create(['reference' => 'BLK-W', 'name' => 'Block']);
    DB::transaction(function () use ($departure, $owner, $holder): void {
        app(ClaimService::class)->claim($departure, collect([$owner]), $holder, ClaimKind::Block);
    });

    $this->actingAs($actor)
        ->postJson('/api/rms/waitlist', waitlistPayload($departure, [
            'cabin_category' => CabinCategory::Owner->value,
            'client' => ['name' => 'Owner wait', 'email' => 'owner-wait@iconic.test'],
        ]))
        ->assertCreated();

    $this->actingAs($actor)
        ->getJson('/api/rms/waitlist?departure_id='.$departure->id)
        ->assertOk()
        ->assertJsonFragment(['cabin_category' => 'OWNER', 'cabin_available' => false]);

    DB::transaction(function () use ($holder): void {
        app(ClaimService::class)->release($holder, ReleaseReason::Released);
    });

    $this->actingAs($actor)
        ->getJson('/api/rms/waitlist?departure_id='.$departure->id)
        ->assertOk()
        ->assertJsonFragment(['cabin_category' => 'OWNER', 'cabin_available' => true]);
});

test('notify records the channel and writes history without sending mail', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $actor = managerUser();

    $id = $this->actingAs($actor)
        ->postJson('/api/rms/waitlist', waitlistPayload($departure))
        ->assertCreated()
        ->json('id');

    $this->actingAs($actor)
        ->postJson('/api/rms/waitlist/'.$id.'/notify', ['channel' => 'EMAIL'])
        ->assertOk()
        ->assertJsonPath('notified.channel', 'EMAIL')
        ->assertJsonPath('notified.by', $actor->name);

    expect(ChangeHistory::query()->where('event', 'waitlist.notified')->count())->toBe(1);
    expect(WaitlistEntry::query()->findOrFail($id)->notified_at)->not->toBeNull();
});
