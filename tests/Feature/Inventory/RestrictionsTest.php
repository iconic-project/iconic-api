<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Restrictions\SetStayRestrictions;
use App\Enums\ClaimKind;
use App\Enums\RoomStatus;
use App\Enums\RoomTypeStatus;
use App\Models\Booking;
use App\Models\ChangeHistory;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Models\StayRestriction;
use App\Services\Inventory\NightAvailability;
use App\Services\Inventory\Restrictions;
use App\Support\Inventory\StaffStayRestrictions;
use App\Support\Stays\StayDates;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Bookings\StayAnchor;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('a type row beats the property row and a null min uses the business rule', function (): void {
    $property = Property::factory()->create();
    $type = restrictionsType($property, 'SUITE');
    $stay = StayDates::forNights('2028-03-03', 2);

    restrictionsApply($property, '2028-03-03', '2028-03-03', [
        'stop_sell' => true,
        'min_stay' => 5,
    ]);

    expect(app(Restrictions::class)->evaluate($type, $stay)->reasons)->toBe([
        'STOP_SELL',
        'MIN_STAY:5',
    ]);

    restrictionsApply($property, '2028-03-03', '2028-03-03', [
        'stop_sell' => false,
        'note' => 'suite',
    ], [$type->id]);

    expect(app(Restrictions::class)->evaluate($type, $stay)->reasons)->toBe([]);
});

test('closed to departure is the check-out date and stop sell ignores that date', function (): void {
    $property = Property::factory()->create();
    $type = restrictionsType($property, 'SUITE');
    $stay = StayDates::forNights('2028-03-03', 2);

    restrictionsApply($property, $stay->lastNight()->toDateString(), $stay->lastNight()->toDateString(), [
        'closed_to_departure' => true,
        'stop_sell' => true,
    ]);

    expect(app(Restrictions::class)->evaluate($type, $stay)->reasons)->toBe(['STOP_SELL']);

    restrictionsApply($property, $stay->checkOut()->toDateString(), $stay->checkOut()->toDateString(), [
        'stop_sell' => true,
        'closed_to_departure' => true,
    ]);

    expect(app(Restrictions::class)->evaluate($type, $stay)->reasons)->toBe([
        'STOP_SELL',
        'CLOSED_TO_DEPARTURE',
    ]);
});

test('min stay and closed to arrival are read from the arrival night only', function (): void {
    $property = Property::factory()->create();
    $type = restrictionsType($property, 'SUITE');
    $stay = StayDates::forNights('2028-03-03', 2);
    $second = $stay->checkIn()->addDay()->toDateString();

    restrictionsApply($property, $second, $second, [
        'min_stay' => 5,
        'closed_to_arrival' => true,
    ]);

    expect(app(Restrictions::class)->evaluate($type, $stay)->reasons)->toBe([]);

    restrictionsApply($property, '2028-03-03', '2028-03-03', [
        'min_stay' => 5,
        'closed_to_arrival' => true,
    ]);

    expect(app(Restrictions::class)->evaluate($type, $stay)->reasons)->toBe([
        'CLOSED_TO_ARRIVAL',
        'MIN_STAY:5',
    ]);
});

test('evaluate lists every failing rule in the stable order', function (): void {
    $property = Property::factory()->create();
    $type = restrictionsType($property, 'SUITE');
    $stay = StayDates::forNights('2028-03-03', 35);

    restrictionsApply($property, '2028-03-03', '2028-03-03', [
        'stop_sell' => true,
        'closed_to_arrival' => true,
        'min_stay' => 40,
    ], [$type->id]);

    restrictionsApply($property, $stay->checkOut()->toDateString(), $stay->checkOut()->toDateString(), [
        'closed_to_departure' => true,
    ], [$type->id]);

    expect(app(Restrictions::class)->evaluate($type, $stay)->reasons)->toBe([
        'STOP_SELL',
        'CLOSED_TO_ARRIVAL',
        'CLOSED_TO_DEPARTURE',
        'MIN_STAY:40',
        'MAX_STAY:30',
    ]);
});

test('a stay inside the business-rule length has no restriction reason', function (): void {
    $property = Property::factory()->create();
    $type = restrictionsType($property, 'SUITE');

    expect(app(Restrictions::class)->evaluate($type, StayDates::forNights('2028-03-03', 7))->reasons)->toBe([]);
    expect(app(Restrictions::class)->evaluate($type, StayDates::forNights('2028-03-03', 31))->reasons)->toBe([
        'MAX_STAY:30',
    ]);
});

test('the inclusive range, weekday filter, and one history row', function (): void {
    $property = Property::factory()->create();

    restrictionsApply($property, '2028-03-03', '2028-03-03', [
        'stop_sell' => true,
    ]);

    expect(StayRestriction::query()->count())->toBe(1);

    restrictionsApply($property, '2028-03-03', '2028-03-06', [
        'closed_to_arrival' => true,
    ], [], [5]);

    $nights = StayRestriction::query()->orderBy('night')->pluck('night')->map->toDateString()->all();

    expect($nights)->toBe(['2028-03-03']);
    expect(StayRestriction::query()->whereDate('night', '2028-03-03')->firstOrFail()->closed_to_arrival)->toBeTrue();
    expect(ChangeHistory::query()->where('event', 'restrictions.set')->count())->toBe(2);

    $entry = ChangeHistory::query()->where('event', 'restrictions.set')->latest('id')->firstOrFail();

    expect($entry->subject_type)->toBe($property->getMorphClass())
        ->and($entry->subject_id)->toBe($property->id)
        ->and($entry->after['inclusive'] ?? null)->toBeTrue()
        ->and($entry->after['nights'] ?? null)->toBe(1)
        ->and($entry->after['weekdays'] ?? null)->toBe([5]);
});

test('a weekday filter that matches nothing still writes one history row', function (): void {
    $property = Property::factory()->create();

    restrictionsApply($property, '2028-03-03', '2028-03-03', [
        'stop_sell' => true,
    ], [], [1]);

    expect(StayRestriction::query()->count())->toBe(0);
    expect(ChangeHistory::query()->where('event', 'restrictions.set')->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'restrictions.set')->firstOrFail()->after['nights'] ?? null)->toBe(0);
});

test('clearing the last field deletes the row and a partial update keeps the rest', function (): void {
    $property = Property::factory()->create();

    restrictionsApply($property, '2028-03-03', '2028-03-03', [
        'stop_sell' => true,
        'min_stay' => 4,
        'note' => 'hold',
    ]);

    restrictionsApply($property, '2028-03-03', '2028-03-03', [
        'stop_sell' => false,
    ]);

    $row = StayRestriction::query()->firstOrFail();

    expect($row->stop_sell)->toBeFalse()
        ->and($row->min_stay)->toBe(4)
        ->and($row->note)->toBe('hold');

    restrictionsApply($property, '2028-03-03', '2028-03-03', [
        'min_stay' => null,
        'note' => null,
    ]);

    expect(StayRestriction::query()->count())->toBe(0);
    expect(ChangeHistory::query()->where('event', 'restrictions.set')->count())->toBe(3);
});

test('an empty room type list is one property-wide row', function (): void {
    $property = Property::factory()->create();
    restrictionsType($property, 'SUITE');
    restrictionsType($property, 'VILLA');

    restrictionsApply($property, '2028-03-03', '2028-03-04', [
        'stop_sell' => true,
    ]);

    expect(StayRestriction::query()->count())->toBe(2);
    expect(StayRestriction::query()->whereNull('room_type_id')->count())->toBe(2);
});

test('min stay longer than max stay is refused', function (): void {
    $property = Property::factory()->create();

    expect(fn () => restrictionsApply($property, '2028-03-03', '2028-03-03', [
        'min_stay' => 5,
        'max_stay' => 2,
    ]))->toThrow(ValidationException::class);

    expect(StayRestriction::query()->count())->toBe(0);
});

test('canBook returns restriction and inventory reasons together', function (): void {
    $property = Property::factory()->create();
    $type = restrictionsType($property, 'SUITE');
    $room = restrictionsRoom($type, 'ONLY');
    restrictionsClaim($room, '2028-06-02');

    restrictionsApply($property, '2028-06-01', '2028-06-01', [
        'stop_sell' => true,
    ], [$type->id]);

    $result = app(NightAvailability::class)->canBook($type, StayDates::forNights('2028-06-01', 2));

    expect($result->ok)->toBeFalse()
        ->and($result->reasons)->toBe(['STOP_SELL', 'SOLD_OUT'])
        ->and($result->nights)->toBe([
            ['night' => '2028-06-01', 'free' => 1],
            ['night' => '2028-06-02', 'free' => 0],
        ]);
});

test('a stopped type blocks a stay that also sells an open type', function (): void {
    $property = Property::factory()->create();
    $stopped = restrictionsType($property, 'SUITE');
    $open = restrictionsType($property, 'VILLA');
    $first = restrictionsRoom($stopped, 'S1');
    $second = restrictionsRoom($open, 'V1');

    restrictionsApply($property, '2028-03-03', '2028-03-03', [
        'stop_sell' => true,
    ], [$stopped->id]);

    expect(fn () => app(StaffStayRestrictions::class)->check(
        [$first, $second],
        StayDates::forNights('2028-03-03', 2),
        managerUser(),
        [],
    ))->toThrow(ValidationException::class);

    $override = app(StaffStayRestrictions::class)->check(
        [$first, $second],
        StayDates::forNights('2028-03-03', 2),
        managerUser(),
        [
            'override_restrictions' => true,
            'restriction_reason' => 'Owner stay',
        ],
    );

    expect($override?->reason)->toBe('Owner stay')
        ->and($override?->codes)->toBe(['STOP_SELL']);
});

test('staff can read restrictions and only the inventory permission can write them', function (): void {
    $property = Property::factory()->create();
    $payload = [
        'property_id' => $property->id,
        'room_type_ids' => [],
        'from' => '2028-03-03',
        'to' => '2028-03-03',
        'stop_sell' => true,
        'reason' => 'Maintenance',
    ];

    $this->actingAs(salesExecUser())
        ->putJson('/api/rms/restrictions', $payload)
        ->assertForbidden();

    $this->actingAs(managerUser())
        ->putJson('/api/rms/restrictions', $payload)
        ->assertOk()
        ->assertJsonPath('range', 'inclusive')
        ->assertJsonPath('from', '2028-03-03')
        ->assertJsonPath('to', '2028-03-03')
        ->assertJsonPath('restrictions.0.night', '2028-03-03')
        ->assertJsonPath('restrictions.0.room_type_id', null)
        ->assertJsonPath('restrictions.0.stop_sell', true)
        ->assertJsonPath('reason_order', [
            'STOP_SELL',
            'CLOSED_TO_ARRIVAL',
            'CLOSED_TO_DEPARTURE',
            'MIN_STAY:n',
            'MAX_STAY:n',
            'SOLD_OUT',
            'NO_SINGLE_ROOM',
        ]);

    $this->actingAs(salesExecUser())
        ->getJson('/api/rms/restrictions?property_id='.$property->id.'&from=2028-03-03&to=2028-03-03')
        ->assertOk()
        ->assertJsonPath('restrictions.0.stop_sell', true);

    $this->actingAs(adminUser())
        ->putJson('/api/rms/restrictions', [
            ...$payload,
            'note' => 'Closed',
        ])
        ->assertOk()
        ->assertJsonPath('restrictions.0.note', 'Closed');

    expect(ChangeHistory::query()->where('event', 'restrictions.set')->count())->toBe(2);
    expect(ChangeHistory::query()->where('event', 'restrictions.set')->firstOrFail()->reason)->toBe('Maintenance');
});

test('a restricted stay is 422 unless staff override with a reason', function (): void {
    $this->seed(InventorySeeder::class);
    $departure = ReservationFixtures::anamaraDeparture();
    restrictionsStopDeparture($departure);
    $payload = ReservationFixtures::createPayload($departure);

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.stay.0', 'STOP_SELL');

    expect(Booking::query()->count())->toBe(0);

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/bookings', [
            ...$payload,
            'override_restrictions' => true,
            'restriction_reason' => 'Guest is already here',
        ])
        ->assertForbidden();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', [
            ...$payload,
            'override_restrictions' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('restriction_reason');

    $this->actingAs(managerUser())
        ->postJson('/api/rms/bookings', [
            ...$payload,
            'override_restrictions' => true,
            'restriction_reason' => 'Guest is already here',
        ])
        ->assertCreated();

    $created = ChangeHistory::query()->where('event', 'booking.created')->firstOrFail();

    expect($created->reason)->toBe('Guest is already here')
        ->and($created->after['override_restrictions'] ?? null)->toBe(['STOP_SELL']);
});

test('a request and a move need the same override', function (): void {
    $this->seed(InventorySeeder::class);
    $source = ReservationFixtures::anamaraDeparture();
    $target = ReservationFixtures::anamaraDeparture('2026-12-23');
    restrictionsStopDeparture($source);
    restrictionsStopDeparture($target);

    $requestPayload = ReservationFixtures::requestPayload($source, [
        'override_restrictions' => true,
        'restriction_reason' => 'Waitlist conversion',
    ]);

    expect(fn () => app(CreateBookingRequest::class)->handle(
        ReservationFixtures::requestPayload($source),
        managerUser(),
    ))->toThrow(ValidationException::class);

    expect(fn () => app(CreateBookingRequest::class)->handle(
        $requestPayload,
        salesExecUser(),
    ))->toThrow(AuthorizationException::class);

    $requested = app(CreateBookingRequest::class)->handle($requestPayload, managerUser());
    $requestedHistory = ChangeHistory::query()->where('event', 'booking.requested')->firstOrFail();

    expect($requestedHistory->reason)->toBe('Waitlist conversion')
        ->and($requestedHistory->after['override_restrictions'] ?? null)->toBe(['STOP_SELL']);

    $open = ReservationFixtures::anamaraDeparture('2026-12-30');
    $manager = managerUser();
    $id = $this->actingAs($manager)
        ->postJson('/api/rms/bookings', ReservationFixtures::createPayload($open, [
            'check_out' => '2026-12-31',
        ]))
        ->assertCreated()
        ->json('bookings.0.id');

    restrictionsStopDeparture($open);

    $roomId = $open->property->rooms->firstWhere('code', 'S2')?->id;

    $preview = $this->actingAs($manager)
        ->postJson('/api/rms/bookings/'.$id.'/move/preview', [
            'room_id' => $roomId,
        ])
        ->assertOk()
        ->json();

    $move = [
        'room_id' => $roomId,
        'confirm_total' => $preview['new_total'],
    ];

    $this->actingAs($manager)
        ->postJson('/api/rms/bookings/'.$id.'/move', $move)
        ->assertUnprocessable()
        ->assertJsonPath('errors.stay.0', 'STOP_SELL');

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/bookings/'.$id.'/move', [
            ...$move,
            'override_restrictions' => true,
            'restriction_reason' => 'Keep the party together',
        ])
        ->assertForbidden();

    $this->actingAs($manager)
        ->postJson('/api/rms/bookings/'.$id.'/move', [
            ...$move,
            'override_restrictions' => true,
            'restriction_reason' => 'Keep the party together',
        ])
        ->assertOk();

    $moved = ChangeHistory::query()->where('event', 'booking.moved')->firstOrFail();

    expect($moved->reason)->toBe('Keep the party together')
        ->and($moved->after['override_restrictions'] ?? null)->toBe(['STOP_SELL']);
});

/**
 * @param  array<string, mixed>  $fields
 * @param  list<int>  $typeIds
 * @param  list<int>|null  $weekdays
 */
function restrictionsApply(
    Property $property,
    string $from,
    string $to,
    array $fields,
    array $typeIds = [],
    ?array $weekdays = null,
): void {
    $payload = [
        'property_id' => $property->id,
        'room_type_ids' => $typeIds,
        'from' => $from,
        'to' => $to,
        ...$fields,
    ];

    if ($weekdays !== null) {
        $payload['weekdays'] = $weekdays;
    }

    app(SetStayRestrictions::class)->handle($payload, managerUser());
}

function restrictionsStopDeparture(StayAnchor $departure): void
{
    $stay = $departure->stayDates();

    restrictionsApply($departure->property, $stay->checkIn()->toDateString(), $stay->lastNight()->toDateString(), [
        'stop_sell' => true,
    ]);
}

function restrictionsType(Property $property, string $code): RoomType
{
    return RoomType::query()->create([
        'property_id' => $property->id,
        'code' => $code,
        'name' => $code,
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'max_adults' => 2,
        'max_children' => 1,
        'waitlist_enabled' => false,
        'sort' => 1,
        'status' => RoomTypeStatus::Active,
    ]);
}

function restrictionsRoom(RoomType $type, string $code): Room
{
    return Room::query()->create([
        'property_id' => $type->property_id,
        'room_type_id' => $type->id,
        'code' => $code,
        'label' => 'Room '.$code,
        'sort' => 1,
        'status' => RoomStatus::Active,
    ]);
}

function restrictionsClaim(Room $room, string $night): void
{
    $holder = ClaimHolder::query()->create([
        'reference' => (string) Str::uuid(),
        'name' => 'Holder',
    ]);

    RoomNightClaim::query()->create([
        'room_id' => $room->id,
        'night' => $night,
        'holder_type' => $holder->getMorphClass(),
        'holder_id' => $holder->getKey(),
        'kind' => ClaimKind::Booking,
        'claim_group' => (string) Str::uuid(),
    ]);
}
