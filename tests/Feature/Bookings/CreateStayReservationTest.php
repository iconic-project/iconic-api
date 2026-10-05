<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\BlockReason;
use App\Enums\BookingStatus;
use App\Enums\ClaimKind;
use App\Enums\Permission;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Group;
use App\Models\InternalBlock;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Models\StayRestriction;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\ClaimService;
use App\Support\BusinessHours;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo('2026-02-01 12:00:00');
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    publishStayRates();
});

test('a wednesday two-night stay sells through the api', function (): void {
    expect(CarbonImmutable::parse('2026-02-04')->isWednesday())->toBeTrue();

    $room = staySaleRoom('101', 1);
    $payload = staySalePayload();
    $payload['expected_total'] = stayQuotedTotal($payload);

    $response = $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', $payload)
        ->assertCreated()
        ->assertJsonPath('group', null)
        ->assertJsonPath('bookings.0.status', BookingStatus::PendingPayment->value)
        ->assertJsonPath('bookings.0.total', 200)
        ->assertJsonPath('bookings.0.deposit_pct', 30)
        ->assertJsonPath('bookings.0.balance_days', 21)
        ->assertJsonPath('bookings.0.rate_plan', 'BAR')
        ->assertJsonPath('bookings.0.stay.check_in', '2026-02-04')
        ->assertJsonPath('bookings.0.stay.check_out', '2026-02-06')
        ->assertJsonPath('bookings.0.stay.nights', 2)
        ->assertJsonPath('bookings.0.departure', null)
        ->assertJsonPath('bookings.0.room.id', $room->id)
        ->assertJsonPath('bookings.0.room_type.code', 'STD')
        ->assertJsonPath('bookings.0.times.expected_arrival_time', '15:00')
        ->assertJsonCount(2, 'bookings.0.night_lines');

    assertNoSensitiveFields($response);

    $booking = Booking::query()->firstOrFail();
    expect($booking->departure_id)->toBeNull();
    expect($booking->night_lines)->toHaveCount(2);
    expect($booking->tax_lines)->toBeArray();
    expect($booking->price_lines)->not->toBeEmpty();
    expect($booking->rates_version_id)->not->toBeNull();
    expect(RoomNightClaim::query()->where('kind', ClaimKind::Booking)->whereNull('released_at')->count())->toBe(2);
    expect(ChangeHistory::query()->where('event', 'booking.created')->count())->toBe(1);
});

test('three rooms with mixed dates become one group', function (): void {
    staySaleRoom('201', 1);
    staySaleRoom('202', 2);
    staySaleRoom('203', 3);

    $rooms = [
        ['room_type' => 'STD', 'adults' => 2, 'child_ages' => []],
        [
            'room_type' => 'STD',
            'adults' => 2,
            'child_ages' => [],
            'check_in' => '2026-02-11',
            'check_out' => '2026-02-13',
        ],
        [
            'room_type' => 'STD',
            'adults' => 2,
            'child_ages' => [],
            'check_in' => '2026-02-18',
            'check_out' => '2026-02-20',
        ],
    ];

    $total = stayQuotedTotal(staySalePayload(['rooms' => [$rooms[0]]]))
        + stayQuotedTotal(staySalePayload([
            'check_in' => '2026-02-11',
            'check_out' => '2026-02-13',
            'rooms' => [[
                'room_type' => 'STD',
                'adults' => 2,
                'child_ages' => [],
            ]],
        ]))
        + stayQuotedTotal(staySalePayload([
            'check_in' => '2026-02-18',
            'check_out' => '2026-02-20',
            'rooms' => [[
                'room_type' => 'STD',
                'adults' => 2,
                'child_ages' => [],
            ]],
        ]));

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', staySalePayload([
            'rooms' => $rooms,
            'expected_total' => $total,
            'group' => ['name' => 'Alvear friends'],
        ]))
        ->assertCreated()
        ->assertJsonPath('group.name', 'Alvear friends')
        ->assertJsonCount(3, 'bookings');

    $group = Group::query()->firstOrFail();
    expect($group->departure_id)->toBeNull();
    expect($group->bookings)->toHaveCount(3);
    expect($group->bookings->pluck('check_in')->map->toDateString()->sort()->values()->all())->toBe([
        '2026-02-04',
        '2026-02-11',
        '2026-02-18',
    ]);
    expect(ChangeHistory::query()->where('event', 'group.created')->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'booking.created')->count())->toBe(3);
});

test('staff can pick a room', function (): void {
    staySaleRoom('101', 1);
    $high = staySaleRoom('102', 10);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', staySalePayload([
            'rooms' => [[
                'room_type' => 'STD',
                'room_id' => $high->id,
                'adults' => 2,
                'child_ages' => [],
            ]],
            'expected_total' => 200,
        ]))
        ->assertCreated()
        ->assertJsonPath('bookings.0.room.id', $high->id);
});

test('the allocator skips a blocked room', function (): void {
    $low = staySaleRoom('101', 1);
    $high = staySaleRoom('102', 10);

    DB::transaction(function () use ($low): void {
        $block = InternalBlock::query()->create([
            'reference' => 'BLK-STAY-01',
            'property_id' => $low->property_id,
            'starts_on' => '2026-02-04',
            'ends_on' => '2026-02-06',
            'reason' => BlockReason::Courtesy,
        ]);

        app(ClaimService::class)->claim(
            StayDates::of('2026-02-04', '2026-02-06'),
            new Collection([$low]),
            $block,
            ClaimKind::Block,
        );
    });

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', staySalePayload([
            'expected_total' => 200,
        ]))
        ->assertCreated()
        ->assertJsonPath('bookings.0.room.id', $high->id);
});

test('sell restrictions come back together and an override needs permission and a reason', function (): void {
    staySaleRoom('101', 1);
    stayStopSell();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', staySalePayload(['expected_total' => 200]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.stay', ['STOP_SELL', 'MIN_STAY:3']);

    expect(Booking::query()->count())->toBe(0);

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/bookings', staySalePayload([
            'expected_total' => 200,
            'override_restrictions' => true,
            'override_reason' => 'Guest already travelling',
        ]))
        ->assertForbidden();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', staySalePayload([
            'expected_total' => 200,
            'override_restrictions' => true,
            'override_reason' => '   ',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['override_reason']);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', staySalePayload([
            'expected_total' => 200,
            'override_restrictions' => true,
            'override_reason' => 'Guest already travelling',
        ]))
        ->assertCreated();

    $history = ChangeHistory::query()->where('event', 'booking.created')->firstOrFail();
    expect($history->reason)->toBe('Guest already travelling');
    expect($history->after['override_restrictions'] ?? null)->toBe(['STOP_SELL', 'MIN_STAY:3']);
});

test('occupancy outside the room type is refused', function (): void {
    staySaleRoom('101', 1);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', staySalePayload([
            'rooms' => [[
                'room_type' => 'STD',
                'adults' => 2,
                'child_ages' => [8],
            ]],
            'expected_total' => 200,
        ]))
        ->assertUnprocessable();

    expect(Booking::query()->count())->toBe(0);
});

test('a drifted total is a 409 and writes no booking', function (): void {
    staySaleRoom('101', 1);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', staySalePayload([
            'expected_total' => 1,
        ]))
        ->assertStatus(409)
        ->assertJsonPath('message', 'The price changed. Review the new quote and submit again.')
        ->assertJsonPath('quote.total', 200);

    expect(Booking::query()->count())->toBe(0);
});

test('create is limited to roles that can sell', function (): void {
    staySaleRoom('101', 1);
    $payload = staySalePayload(['expected_total' => 200]);

    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms],
    ]);

    $this->actingAs(User::factory()->create(['role_id' => $role->id]))
        ->postJson('/api/rms/bookings', $payload)
        ->assertForbidden();

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/bookings', $payload)
        ->assertCreated();
});

test('a user without view all sees only their own stay booking', function (): void {
    staySaleRoom('101', 1);

    $role = Role::factory()->create([
        'permissions' => [Permission::PanelRms, Permission::BookingsCreate],
    ]);
    $owner = User::factory()->create(['role_id' => $role->id]);
    $other = User::factory()->create(['role_id' => $role->id]);

    $id = $this->actingAs($owner)
        ->postJson('/api/rms/bookings', staySalePayload(['expected_total' => 200]))
        ->assertCreated()
        ->json('bookings.0.id');

    $this->actingAs($owner)->getJson('/api/rms/bookings/'.$id)->assertOk();
    $this->actingAs($other)->getJson('/api/rms/bookings/'.$id)->assertForbidden();
});

test('a stay request holds the room until the arrival hold expiry', function (): void {
    staySaleRoom('101', 1);
    $actor = managerUser();

    $booking = app(CreateBookingRequest::class)->handle(staySalePayload([
        'preferred_channel' => 'EMAIL',
        'travel_advisor' => false,
    ]), $actor);

    $rules = app(CurrentConfig::class)->businessRules();
    $expiry = BusinessHours::fromDocument($rules)->holdExpiry(
        now(),
        StayDates::of('2026-02-04', '2026-02-06')->checkIn(),
        $rules,
    );

    expect($booking->status)->toBe(BookingStatus::Requested);
    expect($booking->reference)->toBeNull();
    expect($booking->request_reference)->not->toBeNull();
    expect($booking->departure_id)->toBeNull();
    expect($booking->claims)->toHaveCount(2);
    expect($booking->claims->every(fn (RoomNightClaim $claim): bool => $claim->kind === ClaimKind::Hold))->toBeTrue();
    expect($booking->claims->first()?->expires_at?->equalTo($expiry->expiresAt))->toBeTrue();
});

test('form options list room types, rate plans, and the stay restrictions', function (): void {
    stayStopSell();

    $this->actingAs(managerUser())
        ->getJson('/api/rms/bookings/form-options?check_in=2026-02-04&check_out=2026-02-06')
        ->assertOk()
        ->assertJsonPath('room_types.0.code', 'STD')
        ->assertJsonPath('room_types.0.max_children', 0)
        ->assertJsonPath('room_types.0.restrictions', ['STOP_SELL', 'MIN_STAY:3'])
        ->assertJsonFragment(['code' => 'BAR', 'default' => true, 'deposit_pct' => 30])
        ->assertJsonPath('stay.min_nights', app(CurrentConfig::class)->businessRules()->stay->minNights)
        ->assertJsonPath('stay.max_nights', app(CurrentConfig::class)->businessRules()->stay->maxNights)
        ->assertJsonPath('stay.max_rooms_per_booking', app(CurrentConfig::class)->businessRules()->stay->maxRoomsPerBooking)
        ->assertJsonPath('stay.booking_horizon_days', app(CurrentConfig::class)->businessRules()->stay->bookingHorizonDays);

    $this->actingAs(managerUser())
        ->getJson('/api/rms/bookings/form-options?check_in=2026-02-04')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_out']);
});

test('the bookings index lists a stay and the front desk filters it', function (): void {
    $taken = staySaleRoom('101', 1);
    $free = staySaleRoom('102', 2);
    $payload = staySalePayload();
    $payload['expected_total'] = stayQuotedTotal($payload);
    $manager = managerUser();

    $this->actingAs($manager)
        ->postJson('/api/rms/bookings', $payload)
        ->assertCreated();

    $listed = $this->actingAs($manager)
        ->getJson('/api/rms/bookings?arriving_from=2026-02-04&arriving_to=2026-02-04')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.stay.check_in', '2026-02-04')
        ->assertJsonPath('data.0.departure', null);

    expect($listed->json('data.0.allowed_actions'))->toBe(['modify_stay', 'move_room']);

    $this->actingAs($manager)
        ->getJson('/api/rms/bookings?in_house_on=2026-02-04')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($manager)
        ->getJson('/api/rms/bookings?departing_from=2026-02-06&departing_to=2026-02-06')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($manager)
        ->getJson('/api/rms/properties/'.$taken->property_id.'/rooms?free_from=2026-02-04&free_to=2026-02-06')
        ->assertOk()
        ->assertJsonMissing(['code' => $taken->code])
        ->assertJsonFragment(['code' => $free->code]);
});

function staySaleRoom(string $code, int $sort): Room
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
function staySalePayload(array $overrides = []): array
{
    $payload = [
        'check_in' => '2026-02-04',
        'check_out' => '2026-02-06',
        'rooms' => [[
            'room_type' => 'STD',
            'adults' => 2,
            'child_ages' => [],
        ]],
        'client' => [
            'name' => 'Test Guest',
            'email' => 'guest-'.uniqid().'@iconic.test',
        ],
        'main_channel' => 'D2C',
        'channel_of_origin' => 'Hotel Booking Engine',
        'expected_arrival_time' => '15:00',
    ];

    return array_merge($payload, $overrides);
}

/**
 * @param  array<string, mixed>  $payload
 */
function stayQuotedTotal(array $payload): int
{
    $total = test()->actingAs(managerUser())
        ->postJson('/api/rms/bookings/quote', [
            'check_in' => $payload['check_in'],
            'check_out' => $payload['check_out'],
            'rooms' => $payload['rooms'],
        ])
        ->assertOk()
        ->json('total');

    return (int) $total;
}

function stayStopSell(): void
{
    $type = RoomType::query()->where('code', 'STD')->firstOrFail();

    StayRestriction::query()->create([
        'property_id' => $type->property_id,
        'room_type_id' => $type->id,
        'night' => '2026-02-04',
        'stop_sell' => true,
        'min_stay' => 3,
    ]);
}
