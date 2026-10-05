<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\RoomStatus;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Services\Inventory\ClaimService;
use App\Support\Bookings\RequestSummary;
use App\Support\Iso;
use App\Support\Stays\StayDates;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo('2026-02-01 12:00:00');
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    publishStayRates();
});

test('the stay summary reads rooms, the arrival weekday, and the night count', function (): void {
    expect(RequestSummary::line(2, StayDates::of('2026-03-05', '2026-03-08')))
        ->toBe('2 rooms · Thu 5 – Sun 8 Mar 2026 · 3 nights');
});

test('a stay request hold is created, expired, and converted on the same room', function (): void {
    $room = queueRoom('101', 1);
    $booking = queueRequest($room);

    expect($booking->departure_id)->toBeNull()
        ->and($booking->status)->toBe(BookingStatus::Requested)
        ->and($booking->claims()->whereNull('released_at')->where('kind', ClaimKind::Hold)->pluck('room_id')->unique())->toHaveCount(1)
        ->and($booking->claims()->where('room_id', $room->id)->count())->toBe(2);

    RoomNightClaim::query()
        ->where('holder_id', $booking->id)
        ->whereNull('released_at')
        ->update(['expires_at' => now()->subMinute()]);

    DB::beginTransaction();
    app(ClaimService::class)->releaseExpired();
    expect($booking->fresh()?->bookingRequest?->hold_expired_at)->not->toBeNull();
    DB::rollBack();
    expect($booking->fresh()?->bookingRequest?->hold_expired_at)->toBeNull();

    RoomNightClaim::query()
        ->where('holder_id', $booking->id)
        ->whereNull('released_at')
        ->update(['expires_at' => now()->subMinute()]);
    $this->artisan('inventory:release-expired-holds')->assertSuccessful();

    expect($booking->fresh()?->status)->toBe(BookingStatus::Requested)
        ->and($booking->fresh()?->holdExpired())->toBeTrue()
        ->and($booking->fresh()?->claims()->whereNull('released_at')->count())->toBe(0);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/requests/'.$booking->id.'/confirm')
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::PendingPayment->value)
        ->assertJsonPath('room.id', $room->id);

    expect($booking->fresh()?->claims()->whereNull('released_at')->where('kind', ClaimKind::Booking)->pluck('room_id')->unique())
        ->toHaveCount(1);
});

test('an over-cap stay claims room-nights and release frees them', function (): void {
    $room = queueRoom('201', 1);
    $agency = Agency::factory()->create(['commission_pct' => 15]);
    $payload = queuePayload($room, [
        'main_channel' => 'B2B – Travel Advisor',
        'channel_of_origin' => 'Travel Advisor',
        'agency_id' => $agency->id,
        'commission_pct' => 15,
    ]);
    $payload['expected_total'] = queueTotal($payload);

    $id = $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', $payload)
        ->assertCreated()
        ->json('bookings.0.id');
    $booking = Booking::query()->findOrFail($id);

    expect($booking->status)->toBe(BookingStatus::OnHoldAgency)
        ->and($booking->departure_id)->toBeNull()
        ->and($booking->claims()->whereNull('released_at')->where('kind', ClaimKind::Booking)->count())->toBe(2);

    $this->actingAs(adminUser())
        ->postJson('/api/rms/bookings/'.$booking->id.'/transition', [
            'to' => BookingStatus::Released->value,
            'reason' => 'Sale fell through',
        ])
        ->assertOk()
        ->assertJsonPath('status', BookingStatus::Released->value);

    expect($booking->fresh()?->claims()->whereNull('released_at')->count())->toBe(0);
});

test('confirm after expiry offers another room of the type and does not swap it silently', function (): void {
    $taken = queueRoom('301', 1);
    $free = queueRoom('302', 2);
    $booking = queueRequest($taken);

    RoomNightClaim::query()->where('holder_id', $booking->id)->whereNull('released_at')
        ->update(['expires_at' => now()->subMinute()]);
    $this->artisan('inventory:release-expired-holds')->assertSuccessful();

    $sale = queuePayload($taken);
    $sale['expected_total'] = queueTotal($sale);
    $this->actingAs(managerUser())->postJson('/api/rms/bookings', $sale)->assertCreated();

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/requests/'.$booking->id.'/confirm/preview')
        ->assertOk()
        ->assertJsonPath('alternative', true)
        ->assertJsonPath('room.id', $free->id)
        ->assertJsonPath('room_type.code', 'STD');

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/requests/'.$booking->id.'/confirm')
        ->assertStatus(409)
        ->assertJsonPath('message', 'The original room was taken. Confirm '.$free->label.' to take that room.');

    expect($booking->fresh()?->status)->toBe(BookingStatus::Requested)
        ->and($booking->fresh()?->room_id)->toBe($taken->id);

    $this->actingAs($booking->owner)
        ->postJson('/api/rms/requests/'.$booking->id.'/confirm', ['room_id' => $free->id])
        ->assertOk()
        ->assertJsonPath('room.id', $free->id)
        ->assertJsonPath('status', BookingStatus::PendingPayment->value);
});

test('the request queue sorts by check-in and keeps the SLA', function (): void {
    $room = queueRoom('401', 1);
    $later = queueRequest($room, ['check_in' => '2026-02-11', 'check_out' => '2026-02-13']);
    $this->travel(2)->hours();
    $earlier = queueRequest($room, ['check_in' => '2026-02-04', 'check_out' => '2026-02-06']);

    $response = $this->actingAs($earlier->owner)
        ->getJson('/api/rms/requests')
        ->assertOk()
        ->assertJsonPath('data.0.id', $earlier->id)
        ->assertJsonPath('data.1.id', $later->id)
        ->assertJsonPath('data.0.stay.check_in', '2026-02-04')
        ->assertJsonPath('data.0.stay.check_out', '2026-02-06')
        ->assertJsonPath('data.0.nights', 2)
        ->assertJsonPath('data.0.rooms_count', 1)
        ->assertJsonPath('data.0.room_type.code', 'STD')
        ->assertJsonPath('data.0.copy', '1 room · Wed 4 – Fri 6 Feb 2026 · 2 nights')
        ->assertJsonPath('data.0.sla.breached', false)
        ->assertJsonPath('meta.rules.response_hours', 24)
        ->assertJsonPath('meta.rules.near_term_business_hours', 48)
        ->assertJsonPath('meta.rules.business_day_minutes', 540);

    $due = $earlier->fresh()?->bookingRequest?->sla_due_at;

    expect(array_keys($response->json('data.0')))->not->toContain('departure')
        ->and($response->json('data.0.sla.due_at'))->toBe(Iso::utc($due))
        ->and($later->bookingRequest?->sla_due_at?->lt($due))->toBeTrue();

    $this->actingAs($earlier->owner)
        ->getJson('/api/rms/requests?from=2026-02-11&to=2026-02-11')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $later->id);
});

function queueRoom(string $code, int $sort): Room
{
    $type = RoomType::query()->where('code', 'STD')->firstOrFail();

    return Room::query()->create([
        'property_id' => $type->property_id,
        'room_type_id' => $type->id,
        'code' => $code,
        'label' => 'Room '.$code,
        'sort' => $sort,
        'status' => RoomStatus::Active,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function queuePayload(Room $room, array $overrides = []): array
{
    $payload = [
        'check_in' => '2026-02-04',
        'check_out' => '2026-02-06',
        'rooms' => [[
            'room_type' => 'STD',
            'room_id' => $room->id,
            'adults' => 2,
            'child_ages' => [],
        ]],
        'client' => [
            'name' => 'Queue Guest',
            'email' => 'queue-'.uniqid().'@iconic.test',
        ],
        'main_channel' => 'D2C',
        'channel_of_origin' => 'Hotel Booking Engine',
    ];

    return array_replace_recursive($payload, $overrides);
}

/**
 * @param  array<string, mixed>  $payload
 */
function queueTotal(array $payload): int
{
    return (int) test()->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', [
            'check_in' => $payload['check_in'],
            'check_out' => $payload['check_out'],
            'rooms' => $payload['rooms'],
        ])
        ->assertOk()
        ->json('total');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function queueRequest(Room $room, array $overrides = []): Booking
{
    $payload = queuePayload($room, $overrides);

    return app(CreateBookingRequest::class)->handle($payload, managerUser());
}
