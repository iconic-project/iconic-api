<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\Group;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('users without view_all only see their own bookings', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms, Permission::BookingsCreate],
    ]);
    $owner = User::factory()->create(['role_id' => $role->id]);
    $other = User::factory()->create(['role_id' => $role->id]);

    $mine = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'owner_id' => $owner->id,
    ]);
    Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S2')?->id,
        'owner_id' => $other->id,
    ]);

    $this->actingAs($owner)
        ->getJson('/api/rms/bookings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);

    $this->actingAs($other)
        ->getJson('/api/rms/bookings/'.$mine->id)
        ->assertForbidden();
});

test('can_act follows the own-records rule', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $owner = salesExecUser();
    $other = salesExecUser();
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
        'owner_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('can_act', true);

    $this->actingAs($other)
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('can_act', false);

    $this->actingAs(adminUser())
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('can_act', true)
        ->assertJsonPath('allowed_transitions.0.to', 'CONFIRMED')
        ->assertJsonPath('allowed_transitions.0.reason_required', false)
        ->assertJsonPath('allowed_transitions.1.to', 'CANCELLED')
        ->assertJsonPath('allowed_transitions.1.reason_required', true)
        ->assertJsonPath('balance', $booking->total)
        ->assertJsonPath('request', null)
        ->assertJsonPath('stay.check_in', $booking->check_in->toDateString())
        ->assertJsonPath('stay.check_out', $booking->check_out->toDateString());
});

test('a REQUESTED booking show includes the request summary and notes', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $actor = managerUser();
    $booking = app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($departure, [
            'preferred_channel' => 'WHATSAPP',
            'travel_advisor' => true,
            'notes' => 'Anniversary on board',
        ]),
        $actor,
    );

    $response = $this->actingAs($actor)
        ->getJson('/api/rms/bookings/'.$booking->id)
        ->assertOk()
        ->assertJsonPath('request.preferred_channel', 'WHATSAPP')
        ->assertJsonPath('request.travel_advisor', true)
        ->assertJsonPath('request.notes', 'Anniversary on board')
        ->assertJsonPath('request.hold.expired', false)
        ->assertJsonPath('request.hold.rule', $booking->bookingRequest?->hold_rule->value);

    expect($response->json('request.hold.remaining_business_minutes'))
        ->toBeInt()
        ->toBeGreaterThan(0);
});

test('contacts search returns the top 10 matches', function (): void {
    Contact::factory()->create(['name' => 'Harrison Whitfield', 'email' => 'd.harrison@example.test']);
    Contact::factory()->create(['name' => 'Other', 'email' => 'other@example.test']);

    $this->actingAs(managerUser())
        ->getJson('/api/rms/contacts?q=harrison')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs(managerUser())
        ->getJson('/api/rms/contacts')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('groups are scoped like bookings.view_all', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms, Permission::BookingsCreate],
    ]);
    $owner = User::factory()->create(['role_id' => $role->id]);
    $other = User::factory()->create(['role_id' => $role->id]);
    $group = Group::factory()->create();
    Booking::factory()->create([
        'group_id' => $group->id,
        'owner_id' => $owner->id,
        'room_id' => $departure->property->rooms->firstWhere('code', 'S1')?->id,
    ]);

    $this->actingAs($owner)
        ->getJson('/api/rms/groups')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($other)
        ->getJson('/api/rms/groups')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('groups filter by stay check-in from and to', function (): void {
    $property = ReservationFixtures::anamaraDeparture();
    $novGroup = Group::factory()->create();
    $decGroup = Group::factory()->create();
    Booking::factory()->create([
        'group_id' => $novGroup->id,
        'owner_id' => adminUser()->id,
        'room_id' => $property->property->rooms->firstWhere('code', 'S1')?->id,
        'check_in' => '2027-11-07',
        'check_out' => '2027-11-14',
        'nights' => 7,
    ]);
    Booking::factory()->create([
        'group_id' => $decGroup->id,
        'owner_id' => adminUser()->id,
        'room_id' => $property->property->rooms->firstWhere('code', 'S2')?->id,
        'check_in' => '2027-12-19',
        'check_out' => '2027-12-26',
        'nights' => 7,
    ]);

    $this->actingAs(adminUser())
        ->getJson('/api/rms/groups?from=2027-11-01&to=2027-11-30')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $novGroup->id);

    $this->actingAs(adminUser())
        ->getJson('/api/rms/groups?from=2027-12-01&to=2027-12-31')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $decGroup->id);
});

test('booking owners lists active panel.rms users to records.act_on_any', function (): void {
    $carolina = adminUser(['name' => 'Carolina M.']);
    $mateo = managerUser(['name' => 'Mateo R.']);
    $lucia = salesExecUser(['name' => 'Lucia B.']);
    salesExecUser(['name' => 'Gone', 'status' => UserStatus::Disabled]);

    $this->actingAs($carolina)
        ->getJson('/api/rms/bookings/owners')
        ->assertOk()
        ->assertJsonFragment(['id' => $carolina->id, 'name' => 'Carolina M.'])
        ->assertJsonFragment(['id' => $mateo->id, 'name' => 'Mateo R.'])
        ->assertJsonFragment(['id' => $lucia->id, 'name' => 'Lucia B.'])
        ->assertJsonMissing(['name' => 'Gone']);

    $this->actingAs($lucia)
        ->getJson('/api/rms/bookings/owners')
        ->assertForbidden();
});
