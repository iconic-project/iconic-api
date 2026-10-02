<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Role;
use App\Models\User;
use App\Support\Countries;
use App\Support\SensitiveFields;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoBookingsSeeder;
use Database\Seeders\DemoGuestsSeeder;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\DemoRequestsSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function contactsInNatCabin(User $owner, string $cabin, string $date = '2027-11-07', array $overrides = []): Booking
{
    $departure = ReservationFixtures::anamaraDeparture($date);

    return Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', $cabin)?->id,
        'owner_id' => $owner->id,
        'status' => BookingStatus::Confirmed,
        ...$overrides,
    ]);
}

function contactsInNamedGuest(Booking $booking, string $nationality, int $position = 1): Guest
{
    return Guest::factory()->create([
        'booking_id' => $booking->id,
        'position' => $position,
        'is_lead' => $position === 1,
        'first_name' => 'Guest',
        'last_name' => $nationality.$position,
        'nationality' => $nationality,
    ]);
}

test('the aggregate is the top ten named-guest nationalities', function (): void {
    $actor = managerUser();
    $codes = ['US', 'DE', 'SE', 'GB', 'AR', 'EC', 'FR', 'CO', 'NL', 'IT', 'PT'];

    foreach ($codes as $index => $code) {
        $count = 12 - $index;
        $booking = contactsInNatCabin($actor, 'S1', sprintf('2027-%02d-07', $index + 1));

        for ($position = 1; $position <= $count; $position++) {
            contactsInNamedGuest($booking, $code, $position);
        }

        if ($index === 0) {
            Guest::factory()->create([
                'booking_id' => $booking->id,
                'position' => 20,
                'is_lead' => false,
                'first_name' => 'No',
                'last_name' => 'Country',
                'nationality' => null,
            ]);
        }
    }

    $response = $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in/nationalities')
        ->assertOk()
        ->assertJsonPath('unknown', 1)
        ->json();

    $rows = $response['nationalities'];
    expect($rows)->toHaveCount(10);
    expect($rows[0]['nationality'])->toBe('US');
    expect($rows[0]['country_name'])->toBe(Countries::name('US'));
    expect($rows[0]['guests'])->toBe(12);
    expect(array_column($rows, 'nationality'))->not->toContain('PT');
    expect($response['total_guests'])->toBe(array_sum(array_column($rows, 'guests')) + 3);

    $guestCounts = array_column($rows, 'guests');
    $sorted = $guestCounts;
    rsort($sorted);
    expect($guestCounts)->toBe($sorted);
});

test('empty padded slots are excluded from unknown and total_guests', function (): void {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoInventorySeeder::class);
    $this->seed(DemoBookingsSeeder::class);
    $this->seed(DemoRequestsSeeder::class);
    $this->seed(DemoGuestsSeeder::class);

    $charter = Booking::query()->where('reference', 'ANK-2026-0012')->firstOrFail();
    $emptySlots = $charter->guests()
        ->where('first_name', '')
        ->where('last_name', '')
        ->count();

    expect($emptySlots)->toBeGreaterThan(0);

    $namedUnknown = Guest::query()
        ->named()
        ->where(function ($query): void {
            $query->whereNull('nationality')->orWhere('nationality', '');
        })
        ->whereHas('booking', function ($booking): void {
            $booking->whereNotIn('status', [
                BookingStatus::Cancelled,
                BookingStatus::CancelledPostpaid,
                BookingStatus::Released,
            ]);
        })
        ->count();

    $namedTotal = Guest::query()
        ->named()
        ->whereHas('booking', function ($booking): void {
            $booking->whereNotIn('status', [
                BookingStatus::Cancelled,
                BookingStatus::CancelledPostpaid,
                BookingStatus::Released,
            ]);
        })
        ->count();

    $this->actingAs(adminUser())
        ->getJson('/api/rms/contacts-in/nationalities')
        ->assertOk()
        ->assertJsonPath('unknown', $namedUnknown)
        ->assertJsonPath('total_guests', $namedTotal);

    $nl = collect($this->actingAs(adminUser())->getJson('/api/rms/contacts-in/nationalities')->json('nationalities'))
        ->firstWhere('nationality', 'NL');

    expect($nl)->not->toBeNull();
    expect($nl['guests'])->toBe(1);
    expect($nl['bookings'])->toBe(1);
});

test('cancelled and released bookings are excluded from the aggregate', function (): void {
    $actor = managerUser();
    $kept = contactsInNatCabin($actor, 'S1');
    contactsInNamedGuest($kept, 'US');

    $cancelled = contactsInNatCabin($actor, 'S2', '2027-11-07', ['status' => BookingStatus::Cancelled]);
    contactsInNamedGuest($cancelled, 'DE');

    $released = contactsInNatCabin($actor, 'S3', '2027-11-07', ['status' => BookingStatus::Released]);
    contactsInNamedGuest($released, 'FR');

    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in/nationalities?from=2027-11-01&to=2027-11-30')
        ->assertOk()
        ->assertJsonCount(1, 'nationalities')
        ->assertJsonPath('nationalities.0.nationality', 'US')
        ->assertJsonPath('total_guests', 1)
        ->assertJsonPath('unknown', 0);
});

test('a booking departing outside the window is excluded from the aggregate', function (): void {
    $actor = managerUser();
    $outside = contactsInNatCabin($actor, 'S1', '2027-11-07');
    contactsInNamedGuest($outside, 'US');
    $inside = contactsInNatCabin($actor, 'S2', '2028-06-04');
    contactsInNamedGuest($inside, 'DE');

    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in/nationalities?from=2028-06-01&to=2028-06-30')
        ->assertOk()
        ->assertJsonCount(1, 'nationalities')
        ->assertJsonPath('nationalities.0.nationality', 'DE')
        ->assertJsonPath('total_guests', 1);
});

test('users without view_all only see their guests in the aggregate', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms, Permission::BookingsCreate],
    ]);
    $owner = User::factory()->create(['role_id' => $role->id]);
    $other = User::factory()->create(['role_id' => $role->id]);

    $mine = contactsInNatCabin($owner, 'S1');
    contactsInNamedGuest($mine, 'US');
    $theirs = contactsInNatCabin($other, 'S2');
    contactsInNamedGuest($theirs, 'DE');

    $this->actingAs($owner)
        ->getJson('/api/rms/contacts-in/nationalities')
        ->assertOk()
        ->assertJsonCount(1, 'nationalities')
        ->assertJsonPath('nationalities.0.nationality', 'US')
        ->assertJsonPath('total_guests', 1);
});

test('the same nationalities payload through the CRM guard is stripped', function (): void {
    $actor = managerUser();
    $booking = contactsInNatCabin($actor, 'S1');
    contactsInNamedGuest($booking, 'US');

    $payload = $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in/nationalities')
        ->assertOk()
        ->json();

    expect(SensitiveFields::keysIn($payload))->toContain('nationality');
    expect($payload)->not->toHaveKey('dob');
    expect($payload)->not->toHaveKey('passport_no');

    Route::middleware(['api', 'auth:sanctum', 'active', 'permission:panel.crm', 'crm.sensitive'])
        ->prefix('api/crm')
        ->get('/contacts-in-probe', fn () => response()->json($payload));

    $this->actingAs(adminUser());
    $this->withoutExceptionHandling();

    $this->getJson('/api/crm/contacts-in-probe');
})->throws(RuntimeException::class, 'CRM response contained sensitive fields: nationality');

test('equal guest counts are ordered by country name', function (): void {
    $actor = managerUser();
    $first = contactsInNatCabin($actor, 'S1');
    contactsInNamedGuest($first, 'FR');
    $second = contactsInNatCabin($actor, 'S2');
    contactsInNamedGuest($second, 'DE');

    $this->actingAs($actor)
        ->getJson('/api/rms/contacts-in/nationalities')
        ->assertOk()
        ->assertJsonPath('nationalities.0.nationality', 'FR')
        ->assertJsonPath('nationalities.0.country_name', 'France')
        ->assertJsonPath('nationalities.1.nationality', 'DE')
        ->assertJsonPath('nationalities.1.country_name', 'Germany');
});
