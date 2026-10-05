<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\GuestResponseSource;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Guest;
use App\Models\GuestResponse;
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

test('the departure picker lists sold departures with passengers and is open to panel.rms', function (): void {
    $early = pickerDeparture('2028-06-04', 'Ada', 'Lovelace', BookingStatus::Confirmed);
    $later = pickerDeparture('2028-06-11', 'Grace', 'Hopper', BookingStatus::CheckedOut);
    Guest::factory()->create([
        'booking_id' => $later['booking']->id,
        'position' => 2,
        'is_lead' => false,
        'first_name' => 'Sam',
        'last_name' => 'Hopper',
    ]);
    $empty = ReservationFixtures::anamaraDeparture('2028-06-18');
    $cancelled = pickerDeparture('2028-06-25', 'Alan', 'Turing', BookingStatus::Cancelled);

    $response = $this->actingAs(salesExecUser())
        ->getJson('/api/rms/guest-experience/departures')
        ->assertOk();

    $rows = $response->json('data');
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['departure_id'])->toBe($early['departure']->id)
        ->and($rows[0]['date'])->toBe('2028-06-04')
        ->and($rows[0]['property'])->toBe($early['departure']->property->name)
        ->and($rows[0]['passengers'])->toBe(1)
        ->and($rows[1]['departure_id'])->toBe($later['departure']->id)
        ->and($rows[1]['passengers'])->toBe(2)
        ->and(collect($rows)->pluck('departure_id'))->not->toContain($empty->id, $cancelled['departure']->id);

    expect(array_keys($rows[0]))->toBe(['departure_id', 'date', 'property', 'passengers']);

    $crm = pickerUser([Permission::PanelCrm]);
    $this->actingAs($crm)->getJson('/api/rms/guest-experience/departures')->assertForbidden();
});

test('survey guests are names and cabins, and a user without guest_experience.manage is refused', function (): void {
    $fixture = pickerDeparture('2028-07-02', 'Ada', 'Lovelace', BookingStatus::CheckedOut);
    $companion = Guest::factory()->create([
        'booking_id' => $fixture['booking']->id,
        'position' => 2,
        'is_lead' => false,
        'first_name' => 'Sam',
        'last_name' => 'Lovelace',
        'passport_no' => 'QX-PASSPORT-91',
        'medical_note' => 'QX-MEDICAL-91',
        'dietary_note' => 'QX-DIETARY-91',
        'accessibility_note' => 'QX-ACCESS-91',
        'dob' => '1980-01-02',
        'nationality' => 'GB',
    ]);
    $fixture['guest']->update([
        'passport_no' => 'QX-PASSPORT-LEAD',
        'medical_note' => 'QX-MEDICAL-LEAD',
    ]);

    GuestResponse::query()->create([
        'guest_id' => $companion->id,
        'booking_id' => $fixture['booking']->id,
        'score' => 9,
        'source' => GuestResponseSource::GuestLink,
        'responded_at' => now(),
    ]);

    $manager = managerUser();
    $response = $this->actingAs($manager)
        ->getJson('/api/rms/bookings/'.$fixture['booking']->id.'/survey-guests')
        ->assertOk();

    $rows = $response->json('data');
    $encoded = json_encode($rows);
    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe([
            'guest_id' => $fixture['guest']->id,
            'name' => 'Ada Lovelace',
            'cabin' => $fixture['booking']->cabin->label,
            'responded' => false,
        ])
        ->and($rows[1]['guest_id'])->toBe($companion->id)
        ->and($rows[1]['name'])->toBe('Sam Lovelace')
        ->and($rows[1]['responded'])->toBeTrue()
        ->and($encoded)->not->toContain('QX-PASSPORT')
        ->and($encoded)->not->toContain('QX-MEDICAL')
        ->and($encoded)->not->toContain('QX-DIETARY')
        ->and($encoded)->not->toContain('QX-ACCESS')
        ->and($encoded)->not->toContain('passport')
        ->and($encoded)->not->toContain('medical')
        ->and($encoded)->not->toContain('1980-01-02');

    $withoutManage = pickerUser([
        Permission::PanelRms,
        Permission::BookingsViewAll,
    ]);
    $this->actingAs($withoutManage)
        ->getJson('/api/rms/bookings/'.$fixture['booking']->id.'/survey-guests')
        ->assertForbidden();

    $owner = salesExecUser();
    $fixture['booking']->update(['owner_id' => $owner->id]);
    $this->actingAs($owner)
        ->getJson('/api/rms/bookings/'.$fixture['booking']->id.'/survey-guests')
        ->assertForbidden();

    $other = salesExecUser();
    $this->actingAs($other)
        ->getJson('/api/rms/bookings/'.$fixture['booking']->id.'/survey-guests')
        ->assertForbidden();
});

/**
 * @return array{departure: Departure, booking: Booking, guest: Guest}
 */
function pickerDeparture(string $date, string $first, string $last, BookingStatus $status): array
{
    $departure = ReservationFixtures::anamaraDeparture($date);
    $booking = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S2')?->id,
        'status' => $status,
        'owner_id' => managerUser()->id,
    ]);
    $guest = Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => $first,
        'last_name' => $last,
        'email' => strtolower($first).'@example.com',
    ]);

    return ['departure' => $departure, 'booking' => $booking, 'guest' => $guest];
}

/**
 * @param  list<Permission>  $permissions
 */
function pickerUser(array $permissions): User
{
    $role = Role::factory()->create(['permissions' => $permissions]);

    return User::factory()->create(['role_id' => $role->id]);
}
