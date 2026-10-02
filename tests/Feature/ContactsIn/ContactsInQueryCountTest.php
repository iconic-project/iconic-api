<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Guest;
use App\Models\User;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    $this->seed(DemoInventorySeeder::class);
});

function contactsInCountCabin(User $owner, string $cabin, string $date = '2027-11-07'): Booking
{
    $departure = ReservationFixtures::anamaraDeparture($date);

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', $cabin)?->id,
        'owner_id' => $owner->id,
    ]);
}

test('the contacts-in list query count does not grow with extra bookings', function (): void {
    $actor = managerUser();
    contactsInCountCabin($actor, 'S1');

    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in?from=2027-11-01&to=2027-12-31')
        ->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in?from=2027-11-01&to=2027-12-31')
        ->assertOk();
    $before = count(DB::getQueryLog());

    foreach (['S2', 'S3', 'S4'] as $cabin) {
        contactsInCountCabin($actor, $cabin);
    }

    DB::flushQueryLog();
    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in?from=2027-11-01&to=2027-12-31')
        ->assertOk()
        ->assertJsonCount(4, 'data');
    $after = count(DB::getQueryLog());

    expect($after)->toBe($before);
});

test('the nationalities query count does not grow with extra named guests', function (): void {
    $actor = managerUser();
    $first = contactsInCountCabin($actor, 'S1');
    Guest::factory()->create([
        'booking_id' => $first->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'Ada',
        'last_name' => 'One',
        'nationality' => 'US',
    ]);

    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in/nationalities?from=2027-11-01&to=2027-12-31')
        ->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in/nationalities?from=2027-11-01&to=2027-12-31')
        ->assertOk();
    $before = count(DB::getQueryLog());

    foreach (['S2', 'S3', 'S4'] as $cabin) {
        $booking = contactsInCountCabin($actor, $cabin);
        Guest::factory()->create([
            'booking_id' => $booking->id,
            'position' => 1,
            'is_lead' => true,
            'first_name' => 'Ada',
            'last_name' => $cabin,
            'nationality' => 'US',
        ]);
    }

    DB::flushQueryLog();
    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in/nationalities?from=2027-11-01&to=2027-12-31')
        ->assertOk()
        ->assertJsonPath('nationalities.0.guests', 4);
    $after = count(DB::getQueryLog());

    expect($after)->toBe($before);
});
