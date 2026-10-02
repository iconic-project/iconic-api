<?php

declare(strict_types=1);

use App\Enums\BlockReason;
use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\CabinClaim;
use App\Models\ChangeHistory;
use App\Models\Group;
use App\Models\Role;
use App\Models\User;
use App\Services\Inventory\Availability;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('one cabin creates a pending booking and a BOOKING claim', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'group' => ['name' => 'Should be ignored'],
        ]))
        ->assertCreated()
        ->assertJsonPath('group', null)
        ->assertJsonPath('bookings.0.total', 26600)
        ->assertJsonPath('bookings.0.status', BookingStatus::PendingPayment->value)
        ->assertJsonPath('bookings.0.cabin_label', 'Suite 01');

    $history = ChangeHistory::query()->where('event', 'booking.created')->firstOrFail();
    expect($history->after['what'] ?? '')->toStartWith('Reservation created in RMS — Suite 01 · 2 AD · USD 26,600');

    $this->actingAs(managerUser())
        ->getJson('/api/rms/bookings/'.Booking::query()->firstOrFail()->id.'/history')
        ->assertOk()
        ->assertJsonPath('data.0.event', 'booking.created');

    expect(Booking::query()->count())->toBe(1);
    expect(Group::query()->count())->toBe(0);
    expect(CabinClaim::query()->where('kind', ClaimKind::Booking)->whereNull('released_at')->count())->toBe(1);

    $snapshot = app(Availability::class)->forDepartures(collect([$departure]))[$departure->id];
    $s1 = collect($snapshot->cabins)->firstWhere('cabin.code', 'S1');
    expect($s1['state'])->toBe('SOLD');
    expect($s1['claim']['holder']['detail']['status'])->toBe(BookingStatus::PendingPayment->value);
});

test('three cabins create one group and three bookings', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'cabins' => [
                ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
                ['cabin_code' => 'S2', 'adults' => 2, 'children' => 0],
                ['cabin_code' => 'S3', 'adults' => 2, 'children' => 0],
            ],
            'group' => ['name' => 'Alvear friends'],
        ]))
        ->assertCreated()
        ->assertJsonPath('group.name', 'Alvear friends')
        ->assertJsonCount(3, 'bookings');

    $group = Group::query()->firstOrFail();
    expect($group->bookings)->toHaveCount(3);
    expect(CabinClaim::query()->where('kind', ClaimKind::Booking)->whereNull('released_at')->count())->toBe(3);
    expect(ChangeHistory::query()->where('event', 'group.created')->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'booking.created')->count())->toBe(3);
});

test('a charter claims all nine cabins under one booking', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'type' => 'CHARTER',
            'cabins' => [['adults' => 8, 'children' => 0]],
            'group' => ['name' => 'ignored'],
        ]))
        ->assertCreated()
        ->assertJsonPath('group', null)
        ->assertJsonCount(1, 'bookings')
        ->assertJsonPath('bookings.0.cabin_label', 'Full property')
        ->assertJsonPath('bookings.0.total', 199500);

    $booking = Booking::query()->firstOrFail();
    expect($booking->cabin_id)->toBeNull();
    expect($booking->claims()->whereNull('released_at')->count())->toBe(9);
});

test('an existing group on another departure is 422', function (): void {
    $first = ReservationFixtures::anamaraDeparture('2027-11-07');
    $second = ReservationFixtures::anamaraDeparture('2027-11-14');
    $group = Group::factory()->create(['departure_id' => $first->id]);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($second, [
            'group' => ['existing_group_id' => $group->id],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['group.existing_group_id']);

    expect(Booking::query()->count())->toBe(0);
});

test('existing_group_id must be visible to the actor', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $owner = managerUser();
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms, Permission::BookingsCreate],
    ]);
    $other = User::factory()->create(['role_id' => $role->id]);
    $group = Group::factory()->create(['departure_id' => $departure->id]);
    Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S8')?->id,
        'group_id' => $group->id,
        'owner_id' => $owner->id,
    ]);

    $this->actingAs($other)
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'group' => ['existing_group_id' => $group->id],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['group.existing_group_id']);
});

test('a visible existing group accepts a single cabin', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $actor = managerUser();
    $group = Group::factory()->create([
        'departure_id' => $departure->id,
        'name' => 'Existing',
    ]);
    Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S8')?->id,
        'group_id' => $group->id,
        'owner_id' => $actor->id,
    ]);

    $this->actingAs($actor)
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]],
            'group' => ['existing_group_id' => $group->id],
        ]))
        ->assertCreated()
        ->assertJsonPath('group.reference', $group->reference);

    expect(Booking::query()->where('group_id', $group->id)->count())->toBe(2);
});

test('a taken cabin is 409 and nothing is created', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();

    $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Courtesy->value,
        'departures' => [
            ['departure_id' => $departure->id, 'cabin_codes' => ['S1']],
        ],
    ])->assertCreated();

    $bookingsBefore = Booking::query()->count();
    $claimsBefore = CabinClaim::query()->count();
    $sequencesBefore = DB::table('reference_sequences')->orderBy('scope')->pluck('last_value', 'scope')->all();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure))
        ->assertConflict()
        ->assertJsonPath('unavailable.0.cabin.code', 'S1');

    expect(Booking::query()->count())->toBe($bookingsBefore);
    expect(CabinClaim::query()->count())->toBe($claimsBefore);
    expect(DB::table('reference_sequences')->orderBy('scope')->pluck('last_value', 'scope')->all())->toBe($sequencesBefore);
});

test('a client-sent total is ignored', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', [
            ...ReservationFixtures::createPayload($departure),
            'total' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('bookings.0.total', 26600);
});

test('party errors on create are 422', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => 'S1', 'adults' => 0, 'children' => 0]],
        ]))
        ->assertUnprocessable();

    expect(Booking::query()->count())->toBe(0);
});
