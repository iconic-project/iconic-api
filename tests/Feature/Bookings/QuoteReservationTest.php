<?php

declare(strict_types=1);

use App\Enums\BlockReason;
use App\Enums\Permission;
use App\Models\Booking;
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

test('the eight reference prices come through the quote endpoint', function (array $cabins, string $type, bool $festive, int $total, int $deposit): void {
    $departure = ReservationFixtures::anamaraDeparture($festive ? '2027-12-19' : '2027-11-07', $festive);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', ReservationFixtures::quotePayload($departure, $cabins, $type))
        ->assertOk()
        ->assertJsonPath('total', $total)
        ->assertJsonPath('deposit', $deposit)
        ->assertJsonPath('cabins.0.available', true);
})->with([
    'Suite 2 adults' => [[['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]], 'CABIN', false, 26600, 2660],
    'Suite 1 adult' => [[['cabin_code' => 'S1', 'adults' => 1, 'children' => 0]], 'CABIN', false, 23275, 2328],
    'Suite 3 adults' => [[['cabin_code' => 'S1', 'adults' => 3, 'children' => 0]], 'CABIN', false, 35910, 3591],
    'Suite 2 adults + 1 child' => [[['cabin_code' => 'S1', 'adults' => 2, 'children' => 1]], 'CABIN', false, 37905, 3791],
    'Owner 2 adults' => [[['cabin_code' => 'OWNER', 'adults' => 2, 'children' => 0]], 'CABIN', false, 50000, 5000],
    'Suite festive' => [[['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]], 'CABIN', true, 28100, 2810],
    'Charter' => [[['adults' => 8, 'children' => 0]], 'CHARTER', false, 199500, 39900],
    'Charter festive' => [[['adults' => 8, 'children' => 0]], 'CHARTER', true, 211500, 42300],
]);

test('a missing adult count uses the party message', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', [
            'departure_id' => $departure->id,
            'type' => 'CABIN',
            'cabins' => [['cabin_code' => 'S1', 'children' => 0]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'cabins.0.adults' => 'At least 1 adult is required.',
        ]);
});

test('party errors and the child warning appear on the cabin', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', ReservationFixtures::quotePayload($departure, [
            ['cabin_code' => 'S1', 'adults' => 0, 'children' => 2],
        ]))
        ->assertOk()
        ->assertJsonPath('cabins.0.errors.0', 'At least 1 adult is required.')
        ->assertJsonPath('cabins.0.warnings.0', 'Child rate allows max 1 child per adult (2 per couple).')
        ->assertJsonPath('total', null);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', ReservationFixtures::quotePayload($departure, [
            ['cabin_code' => 'S1', 'adults' => 3, 'children' => 1],
        ]))
        ->assertOk()
        ->assertJsonFragment(['A suite takes up to 3 guests — 4 entered. Split into more cabins or book as a group.']);
});

test('NoRate is an error on the cabin', function (): void {
    $departure = ReservationFixtures::anamaraDeparture('2026-11-01');

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', ReservationFixtures::quotePayload($departure, [
            ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
        ]))
        ->assertOk()
        ->assertJsonPath('cabins.0.errors.0', 'No 2026 Suite rate');
});

test('charter is unavailable when any cabin is taken', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();

    $room = $departure->property->rooms()->where('code', 'S8')->firstOrFail();
    $stay = $departure->stayDates();

    $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Courtesy->value,
        'starts_on' => $stay->checkIn()->toDateString(),
        'ends_on' => $stay->checkOut()->toDateString(),
        'rooms' => [$room->id],
    ])->assertCreated();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', ReservationFixtures::quotePayload($departure, [
            ['adults' => 8, 'children' => 0],
        ], 'CHARTER'))
        ->assertOk()
        ->assertJsonPath('cabins.0.available', false)
        ->assertJsonPath('total', 199500);
});

test('quote terms come from the published rates and business rules', function (): void {
    $cabin = ReservationFixtures::anamaraDeparture();
    $charter = ReservationFixtures::anamaraDeparture('2027-12-19', true);

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/bookings/quote', ReservationFixtures::quotePayload($cabin, [
            ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
        ]))
        ->assertOk()
        ->assertJsonPath('terms.balance_days', 120)
        ->assertJsonPath('terms.charter', null);

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/bookings/quote', ReservationFixtures::quotePayload($charter, [
            ['adults' => 8, 'children' => 0],
        ], 'CHARTER'))
        ->assertOk()
        ->assertJsonPath('terms.balance_days', 120)
        ->assertJsonPath('terms.charter.deposit_pct', 20)
        ->assertJsonPath('terms.charter.deposit_business_days', 5)
        ->assertJsonPath('terms.charter.balance_days', 120)
        ->assertJsonPath('terms.charter.dpng_manifest_days', 30);
});

test('quote requires bookings.create', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms],
    ]);
    $user = User::factory()->create(['role_id' => $role->id]);
    $departure = ReservationFixtures::anamaraDeparture();

    $this->actingAs($user)
        ->postJson('/api/rms/bookings/quote', ReservationFixtures::quotePayload($departure, [
            ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
        ]))
        ->assertForbidden();

    expect(Booking::query()->count())->toBe(0);
});
