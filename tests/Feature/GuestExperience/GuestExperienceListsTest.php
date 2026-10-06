<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\GuestResponseSource;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\GuestResponse;
use App\Models\Role;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Bookings\StayAnchor;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('the arrival list is a check-in range and the questionnaire sends before check-in', function (): void {
    $early = pickerDeparture('2028-06-04', 'Ada', 'Lovelace', BookingStatus::Confirmed);
    $later = pickerDeparture('2028-06-11', 'Grace', 'Hopper', BookingStatus::CheckedOut);
    Guest::factory()->create([
        'booking_id' => $later['booking']->id,
        'position' => 2,
        'is_lead' => false,
        'first_name' => 'Sam',
        'last_name' => 'Hopper',
    ]);
    pickerDeparture('2028-06-25', 'Alan', 'Turing', BookingStatus::Cancelled);
    ReservationFixtures::anamaraDeparture('2028-06-18');

    $this->travelTo(CarbonImmutable::parse('2028-04-20 12:00:00', BusinessTime::zone()));

    $response = $this->actingAs(salesExecUser())
        ->getJson('/api/rms/guest-experience?from=2028-06-04&to=2028-06-11')
        ->assertOk();

    $guests = $response->json('data.guests');
    expect($guests)->toHaveCount(3)
        ->and($guests[0]['name'])->toBe('Ada Lovelace')
        ->and($guests[0]['send_date'])->toBe('2028-04-20')
        ->and($guests[0]['status'])->toBe('SENT_NO_REPLY')
        ->and($guests[1]['name'])->toBe('Grace Hopper')
        ->and($guests[1]['send_date'])->toBe('2028-04-27')
        ->and($guests[1]['status'])->toBe('SCHEDULED')
        ->and(collect($guests)->pluck('name'))->not->toContain('Alan Turing')
        ->and($response->json('data.send_date'))->toBe('2028-04-20')
        ->and($response->json('data.kpis.bookings'))->toBe(2)
        ->and($early['booking']->stay()->checkIn()->toDateString())->toBe('2028-06-04');

    $this->actingAs(salesExecUser())
        ->getJson('/api/rms/guest-experience?from=2028-06-04&to=2028-06-04')
        ->assertOk()
        ->assertJsonPath('data.kpis.guests', 1)
        ->assertJsonPath('data.guests.0.send_date', '2028-04-20');

    $crm = pickerUser([Permission::PanelCrm]);
    $this->actingAs($crm)->getJson('/api/rms/guest-experience?from=2028-06-04&to=2028-06-11')->assertForbidden();
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
            'room' => $fixture['booking']->roomLabel(),
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
 * @return array{departure: StayAnchor, booking: Booking, guest: Guest}
 */
function pickerDeparture(string $date, string $first, string $last, BookingStatus $status): array
{
    $departure = ReservationFixtures::anamaraDeparture($date);
    $booking = Booking::factory()->create([
        'room_id' => $departure->property->rooms->firstWhere('code', 'S2')?->id,
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
