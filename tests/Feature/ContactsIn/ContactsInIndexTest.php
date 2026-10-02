<?php

declare(strict_types=1);

use App\Actions\Extras\AddBookingExtra;
use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\BookingRequest;
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

function contactsInCabin(User $owner, string $cabin, string $date = '2027-11-07', array $overrides = []): Booking
{
    $departure = ReservationFixtures::anamaraDeparture($date);

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', $cabin)?->id,
        'owner_id' => $owner->id,
        ...$overrides,
    ]);
}

function contactsInOwnRecordsUser(): User
{
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms, Permission::BookingsCreate],
    ]);

    return User::factory()->create(['role_id' => $role->id]);
}

test('the list is one row per booking with charges_total as the value', function (): void {
    $actor = managerUser();
    $booking = contactsInCabin($actor, 'S1', '2027-11-07', [
        'reference' => 'ANK-2026-0501',
        'status' => BookingStatus::Confirmed,
    ]);
    app(AddBookingExtra::class)->handle($booking, ['code' => 'FLT', 'qty' => 1], $actor);

    $booking->refresh();
    expect($booking->chargesTotal())->toBeGreaterThan($booking->total);

    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in?from=2027-11-01&to=2027-11-30')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $booking->id)
        ->assertJsonPath('data.0.display_reference', 'ANK-2026-0501')
        ->assertJsonPath('data.0.status', BookingStatus::Confirmed->value)
        ->assertJsonPath('data.0.segment', $booking->segment()->value)
        ->assertJsonPath('data.0.main_channel', $booking->main_channel->value)
        ->assertJsonPath('data.0.channel_of_origin', $booking->channel_of_origin->value)
        ->assertJsonPath('data.0.charges_total', $booking->chargesTotal())
        ->assertJsonPath('data.0.contact.id', $booking->contact_id)
        ->assertJsonPath('data.0.contact.name', $booking->contact->name)
        ->assertJsonPath('data.0.travel_advisor', false)
        ->assertJsonPath('data.0.owner.id', $actor->id)
        ->assertJsonPath('data.0.can_act', true)
        ->assertJsonMissingPath('data.0.dob')
        ->assertJsonMissingPath('data.0.passport_no')
        ->assertJsonMissingPath('data.0.medical_note');
});

test('a request from a travel advisor is flagged', function (): void {
    $actor = managerUser();
    $booking = contactsInCabin($actor, 'S1', '2027-11-07', [
        'reference' => null,
        'request_reference' => 'ANK-R-2026-0501',
        'status' => BookingStatus::Requested,
    ]);
    BookingRequest::factory()->create([
        'booking_id' => $booking->id,
        'travel_advisor' => true,
    ]);

    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in?from=2027-11-01&to=2027-11-30')
        ->assertOk()
        ->assertJsonPath('data.0.display_reference', 'ANK-R-2026-0501')
        ->assertJsonPath('data.0.travel_advisor', true);
});

test('users without view_all only see their own rows', function (): void {
    $owner = contactsInOwnRecordsUser();
    $other = contactsInOwnRecordsUser();
    $mine = contactsInCabin($owner, 'S1');
    contactsInCabin($other, 'S2');

    $this->actingAs($owner)
        ->getJson('/api/rms/contacts-in')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
});

test('a booking departing outside the window is excluded', function (): void {
    $actor = managerUser();
    contactsInCabin($actor, 'S1', '2027-11-07');
    $inside = contactsInCabin($actor, 'S2', '2028-06-04');

    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in?from=2028-06-01&to=2028-06-30')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inside->id);
});

test('soft-deleted bookings are excluded', function (): void {
    $actor = managerUser();
    $kept = contactsInCabin($actor, 'S1');
    $deleted = contactsInCabin($actor, 'S2');
    $deleted->delete();

    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $kept->id);
});

test('cancelled bookings stay on the list', function (): void {
    $actor = managerUser();
    $cancelled = contactsInCabin($actor, 'S1', '2027-11-07', [
        'status' => BookingStatus::Cancelled,
    ]);

    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in?from=2027-11-01&to=2027-11-30')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $cancelled->id)
        ->assertJsonPath('data.0.status', BookingStatus::Cancelled->value);
});

test('the list is paginated', function (): void {
    $actor = managerUser();
    contactsInCabin($actor, 'S1');
    contactsInCabin($actor, 'S2');

    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in?per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 1);
});
