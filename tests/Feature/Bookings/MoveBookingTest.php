<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\ConfigKind;
use App\Enums\HoldType;
use App\Models\Booking;
use App\Models\CabinClaim;
use App\Models\ChangeHistory;
use App\Models\Group;
use App\Services\Config\ConfigPublisher;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\Availability;
use App\Services\Inventory\ClaimService;
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

function reservedOn(string $date = '2027-11-07', string $cabin = 'S1', bool $festive = false): Booking
{
    $departure = ReservationFixtures::anamaraDeparture($date, $festive);
    $id = test()->actingAs(adminUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'cabins' => [['cabin_code' => $cabin, 'adults' => 2, 'children' => 0]],
        ]))
        ->assertCreated()
        ->json('bookings.0.id');

    return Booking::query()->findOrFail($id);
}

test('same departure other cabin requotes and moves the claim', function (): void {
    $booking = reservedOn();

    $preview = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move/preview', [
            'departure_id' => $booking->departure_id,
            'cabin_code' => 'S2',
        ])
        ->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('difference', 0)
        ->json();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move', [
            'departure_id' => $booking->departure_id,
            'cabin_code' => 'S2',
            'confirm_total' => $preview['new_total'],
        ])
        ->assertOk()
        ->assertJsonPath('cabin.code', 'S2')
        ->assertJsonPath('total', 26600);

    $snapshot = app(Availability::class)->forDepartures(collect([$booking->departure]))[$booking->departure_id];
    expect(collect($snapshot->cabins)->firstWhere('cabin.code', 'S1')['state'])->toBe('FREE');
    expect(collect($snapshot->cabins)->firstWhere('cabin.code', 'S2')['state'])->toBe('SOLD');
});

test('same departure and same cabin is nothing to move', function (): void {
    $booking = reservedOn();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move/preview', [
            'departure_id' => $booking->departure_id,
            'cabin_code' => 'S1',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.departure_id.0', 'Nothing to move.');
});

test('moving to 19 dec 2027 festive adds the supplement and moves the claim', function (): void {
    $booking = reservedOn();
    $origin = $booking->departure;
    $festive = ReservationFixtures::anamaraDeparture('2027-12-19', festive: true);

    $preview = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move/preview', [
            'departure_id' => $festive->id,
            'cabin_code' => 'S1',
        ])
        ->assertOk()
        ->assertJsonPath('festive_changes', true)
        ->assertJsonPath('new_total', 28100)
        ->json();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move', [
            'departure_id' => $festive->id,
            'cabin_code' => 'S1',
            'confirm_total' => $preview['new_total'],
        ])
        ->assertOk()
        ->assertJsonPath('total', 28100)
        ->assertJsonPath('deposit_pct', 10);

    $booking->refresh();
    expect($booking->balance_days)->toBe(120);
    expect($booking->departure_id)->toBe($festive->id);

    $oldSnap = app(Availability::class)->forDepartures(collect([$origin]))[$origin->id];
    expect(collect($oldSnap->cabins)->firstWhere('cabin.code', 'S1')['state'])->toBe('FREE');

    $newSnap = app(Availability::class)->forDepartures(collect([$festive]))[$festive->id];
    expect(collect($newSnap->cabins)->firstWhere('cabin.code', 'S1')['state'])->toBe('SOLD');

    $history = ChangeHistory::query()->where('event', 'booking.moved')->latest('id')->firstOrFail();
    expect($history->before['departure'] ?? '')->toContain('7 Nov 2027');
    expect($history->after['departure'] ?? '')->toContain('19 Dec 2027');
});

test('a published rates version is stored on the moved booking', function (): void {
    $booking = reservedOn();
    $saleVersion = $booking->rates_version_id;
    $target = ReservationFixtures::anamaraDeparture('2027-11-14');
    $document = ratesDocument();
    $document['years'][1]['suite_pp'] = ((int) $document['years'][1]['suite_pp']) + 100;
    $current = app(CurrentConfig::class)->version(ConfigKind::Rates);

    app(ConfigPublisher::class)->publish(
        ConfigKind::Rates,
        $document,
        $current->version,
        'MOVE-RATES',
        adminUser(),
    );

    $preview = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move/preview', [
            'departure_id' => $target->id,
            'cabin_code' => 'S1',
        ])
        ->assertOk()
        ->json();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move', [
            'departure_id' => $target->id,
            'cabin_code' => 'S1',
            'confirm_total' => $preview['new_total'],
        ])
        ->assertOk();

    expect((int) $booking->fresh()->rates_version_id)->not->toBe($saleVersion);
    expect((int) $booking->fresh()->deposit_pct)->toBe(10);
});

test('a stale confirm_total is 409 and writes nothing', function (): void {
    $booking = reservedOn();
    $target = ReservationFixtures::anamaraDeparture('2027-11-14');

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move', [
            'departure_id' => $target->id,
            'cabin_code' => 'S1',
            'confirm_total' => 1,
        ])
        ->assertStatus(409)
        ->assertJsonPath('message', 'The price changed since the preview (USD 1 → USD 26,600). Review and confirm again.');

    expect($booking->fresh()->departure_id)->toBe($booking->departure_id);
    expect($booking->fresh()->claims()->whereNull('released_at')->count())->toBe(1);
});

test('a sold target cabin is 409 and leaves the booking unchanged', function (): void {
    $first = reservedOn('2027-11-07', 'S1');
    $second = reservedOn('2027-11-14', 'S1');

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$first->id.'/move', [
            'departure_id' => $second->departure_id,
            'cabin_code' => 'S1',
            'confirm_total' => 26600,
        ])
        ->assertStatus(409);

    expect($first->fresh()->departure_id)->toBe($first->departure_id);
});

test('a group cannot move to another departure but can change cabin', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $created = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'cabins' => [
                ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
                ['cabin_code' => 'S2', 'adults' => 2, 'children' => 0],
            ],
            'group' => ['name' => 'Alvear'],
        ]))
        ->assertCreated()
        ->json();

    $bookingId = $created['bookings'][0]['id'];
    $group = Group::query()->firstOrFail();
    $other = ReservationFixtures::anamaraDeparture('2027-11-14');

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$bookingId.'/move', [
            'departure_id' => $other->id,
            'cabin_code' => 'S3',
            'confirm_total' => 26600,
        ])
        ->assertStatus(409)
        ->assertJsonPath('message', 'This booking belongs to '.$group->reference.' — moving a group to another departure isn\'t supported yet.');

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$bookingId.'/move', [
            'departure_id' => $departure->id,
            'cabin_code' => 'S3',
            'confirm_total' => 26600,
        ])
        ->assertOk()
        ->assertJsonPath('cabin.code', 'S3');
});

test('a charter moves all nine claims', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $id = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($departure, [
            'type' => 'CHARTER',
            'cabins' => [['adults' => 8, 'children' => 0]],
        ]))
        ->assertCreated()
        ->json('bookings.0.id');

    $target = ReservationFixtures::anamaraDeparture('2027-11-14');
    $preview = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$id.'/move/preview', [
            'departure_id' => $target->id,
        ])
        ->assertOk()
        ->json();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$id.'/move', [
            'departure_id' => $target->id,
            'confirm_total' => $preview['new_total'],
        ])
        ->assertOk()
        ->assertJsonPath('cabin_label', 'Full property');

    expect(CabinClaim::query()->where('holder_id', $id)->whereNull('released_at')->count())->toBe(9);
    expect(CabinClaim::query()->where('holder_id', $id)->whereNull('released_at')->where('departure_id', $target->id)->count())->toBe(9);
});

test('requested with no active claim cannot be moved', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $cabin = $departure->property->cabins->firstWhere('code', 'S4');
    $booking = Booking::factory()->create([
        'status' => BookingStatus::Requested,
        'reference' => null,
        'request_reference' => 'ANK-R-2026-0099',
        'departure_id' => $departure->id,
        'room_id' => $cabin?->id,
        'owner_id' => managerUser()->id,
    ]);

    $target = ReservationFixtures::anamaraDeparture('2027-11-14');

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move/preview', [
            'departure_id' => $target->id,
            'cabin_code' => 'S4',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.departure_id.0', "This request's hold has expired — confirm or release it first.");
});

test('a requested hold moves and keeps its expiry', function (): void {
    $departure = ReservationFixtures::anamaraDeparture();
    $cabin = $departure->property->cabins->firstWhere('code', 'S5');
    $booking = Booking::factory()->create([
        'status' => BookingStatus::Requested,
        'reference' => null,
        'request_reference' => 'ANK-R-2026-0098',
        'departure_id' => $departure->id,
        'room_id' => $cabin?->id,
        'owner_id' => managerUser()->id,
    ]);
    $expires = now()->addDays(3);

    DB::transaction(function () use ($departure, $cabin, $booking, $expires): void {
        app(ClaimService::class)->claim(
            $departure,
            collect([$cabin]),
            $booking,
            ClaimKind::Hold,
            HoldType::Request,
            $expires,
        );
    });

    $target = ReservationFixtures::anamaraDeparture('2027-11-14');
    $preview = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move/preview', [
            'departure_id' => $target->id,
            'cabin_code' => 'S5',
        ])
        ->assertOk()
        ->json();

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move', [
            'departure_id' => $target->id,
            'cabin_code' => 'S5',
            'confirm_total' => $preview['new_total'],
        ])
        ->assertOk();

    $claim = CabinClaim::query()->where('holder_id', $booking->id)->whereNull('released_at')->firstOrFail();
    expect($claim->kind)->toBe(ClaimKind::Hold);
    expect($claim->departure_id)->toBe($target->id);
    expect($claim->expires_at?->getTimestamp())->toBe($expires->getTimestamp());
});

test('fin-006 above zero is added to the preview and the stored lines', function (): void {
    $booking = reservedOn();
    $target = ReservationFixtures::anamaraDeparture('2027-11-14');
    $current = app(CurrentConfig::class)->version(ConfigKind::BusinessRules);

    app(ConfigPublisher::class)->publish(
        ConfigKind::BusinessRules,
        businessRulesDocument(['modification_fee_usd' => 500]),
        $current->version,
        'FIN-006-TEST',
        adminUser(),
    );

    $preview = $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move/preview', [
            'departure_id' => $target->id,
            'cabin_code' => 'S1',
        ])
        ->assertOk()
        ->assertJsonPath('new_total', 27100)
        ->assertJsonPath('difference', 500)
        ->json();

    expect(collect($preview['new_price_lines'])->firstWhere('code', 'modification_fee')['label'] ?? null)
        ->toBe('Modification fee (FIN-006)');

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/move', [
            'departure_id' => $target->id,
            'cabin_code' => 'S1',
            'confirm_total' => 27100,
        ])
        ->assertOk()
        ->assertJsonPath('total', 27100);
});
