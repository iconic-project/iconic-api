<?php

declare(strict_types=1);

use App\Enums\BookabilityReason;
use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\RoomNightState;
use App\Enums\RoomStatus;
use App\Enums\RoomTypeStatus;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomNightClaim;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Inventory\NightAvailability;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
});

test('the grid states each claim kind and an expired hold is free', function (): void {
    $this->seed(ConfigSeeder::class);
    $property = Property::factory()->create();
    $type = nightType($property, 'SUITE', 'Suite');
    $sold = nightRoom($type, 'S1', 1);
    $held = nightRoom($type, 'S2', 2);
    $blocked = nightRoom($type, 'S3', 3);
    $expired = nightRoom($type, 'S4', 4);
    $free = nightRoom($type, 'S5', 5);
    $owner = User::factory()->create(['name' => 'Ada Owner']);
    $booking = Booking::factory()->create([
        'owner_id' => $owner->id,
        'reference' => 'ANK-NIGHT-1',
        'room_id' => $sold->id,
    ]);
    Guest::factory()->create([
        'booking_id' => $booking->id,
        'is_lead' => true,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);

    nightClaim($sold, '2028-06-10', ClaimKind::Booking, holder: $booking, group: 'group-sold');
    nightClaim($held, '2028-06-10', ClaimKind::Hold, now()->addDay(), group: 'group-held');
    nightClaim($blocked, '2028-06-10', ClaimKind::Block, group: 'group-block');
    nightClaim($expired, '2028-06-10', ClaimKind::Hold, now()->subMinute());

    $grid = app(NightAvailability::class)->grid(
        $property,
        CarbonImmutable::parse('2028-06-10'),
        CarbonImmutable::parse('2028-06-11'),
    );

    $byCode = collect($grid->rooms)->keyBy('code');

    expect($byCode['S1']['cells'][0]['state'])->toBe(RoomNightState::Sold->value)
        ->and($byCode['S1']['cells'][0]['claim']['claim_group'])->toBe('group-sold')
        ->and($byCode['S1']['cells'][0]['claim']['reference'])->toBe('ANK-NIGHT-1')
        ->and($byCode['S1']['cells'][0]['claim']['guest_surname'])->toBe('Lovelace')
        ->and($byCode['S1']['cells'][0]['claim']['owner'])->toBe('Ada Owner')
        ->and($byCode['S1']['cells'][0]['claim']['holder_type'])->toBe('booking')
        ->and($byCode['S1']['cells'][0]['claim']['holder_id'])->toBe($booking->id)
        ->and($byCode['S2']['cells'][0]['state'])->toBe(RoomNightState::Held->value)
        ->and($byCode['S2']['cells'][0]['claim']['claim_group'])->toBe('group-held')
        ->and($byCode['S3']['cells'][0]['state'])->toBe(RoomNightState::Blocked->value)
        ->and($byCode['S4']['cells'][0]['state'])->toBe(RoomNightState::Free->value)
        ->and($byCode['S4']['cells'][0]['claim'])->toBeNull()
        ->and($byCode['S5']['cells'][0]['state'])->toBe(RoomNightState::Free->value)
        ->and($byCode[$free->code]['cells'][0]['claim'])->toBeNull();
});

test('counts match a brute-force pass over the same rooms and claims', function (): void {
    $property = Property::factory()->create();
    $suite = nightType($property, 'SUITE', 'Suite');
    $villa = nightType($property, 'VILLA', 'Villa', 2);
    $suiteA = nightRoom($suite, 'S1', 1);
    $suiteB = nightRoom($suite, 'S2', 2);
    nightRoom($villa, 'V1', 1);
    nightClaim($suiteA, '2028-06-10', ClaimKind::Booking);
    nightClaim($suiteA, '2028-06-11', ClaimKind::Hold, now()->addDay());
    nightClaim($suiteB, '2028-06-10', ClaimKind::Block);

    $from = CarbonImmutable::parse('2028-06-10');
    $to = CarbonImmutable::parse('2028-06-12');
    $counts = app(NightAvailability::class)->countsByType($property, $from, $to);

    expect($counts)->toBe(nightBruteCounts($property, $from, $to));
    expect($counts['2028-06-10']['SUITE'])->toBe([
        'total' => 2,
        'free' => 0,
        'held' => 0,
        'sold' => 1,
        'blocked' => 1,
    ]);
    expect($counts['2028-06-11']['VILLA']['free'])->toBe(1);
});

test('occupancy drops blocked room-nights from the available base', function (): void {
    $property = Property::factory()->create();
    $type = nightType($property, 'SUITE', 'Suite');
    $sold = nightRoom($type, 'S1', 1);
    $blocked = nightRoom($type, 'S2', 2);
    nightRoom($type, 'S3', 3);
    nightClaim($sold, '2028-06-10', ClaimKind::Booking);
    nightClaim($blocked, '2028-06-10', ClaimKind::Block);

    $occupancy = app(NightAvailability::class)->occupancy(
        $property,
        CarbonImmutable::parse('2028-06-10'),
        CarbonImmutable::parse('2028-06-11'),
    );

    expect($occupancy)->toBe([
        'room_nights_available' => 2,
        'room_nights_sold' => 1,
        'pct' => 50,
    ]);
});

test('canBook returns NO_SINGLE_ROOM when every night is free on a different room', function (): void {
    $property = Property::factory()->create();
    $type = nightType($property, 'SUITE', 'Suite');
    $first = nightRoom($type, 'A', 1);
    $second = nightRoom($type, 'B', 2);
    nightClaim($first, '2028-07-02', ClaimKind::Booking);
    nightClaim($second, '2028-07-01', ClaimKind::Booking);

    $result = app(NightAvailability::class)->canBook($type, StayDates::forNights('2028-07-01', 2));

    expect($result->ok)->toBeFalse()
        ->and($result->reasons)->toBe([BookabilityReason::NoSingleRoom->value])
        ->and($result->nights)->toBe([
            ['night' => '2028-07-01', 'free' => 1],
            ['night' => '2028-07-02', 'free' => 1],
        ]);
});

test('canBook returns SOLD_OUT when one night has too few rooms', function (): void {
    $property = Property::factory()->create();
    $type = nightType($property, 'SUITE', 'Suite');
    $room = nightRoom($type, 'ONLY', 1);
    nightClaim($room, '2028-09-03', ClaimKind::Booking);

    $result = app(NightAvailability::class)->canBook($type, StayDates::forNights('2028-09-01', 3));

    expect($result->ok)->toBeFalse()
        ->and($result->reasons)->toBe([BookabilityReason::SoldOut->value]);
});

test('kpis count fully sold nights and nights at or under the threshold', function (): void {
    $property = Property::factory()->create();
    $type = nightType($property, 'SUITE', 'Suite');
    $first = nightRoom($type, 'S1', 1);
    $second = nightRoom($type, 'S2', 2);
    nightRoom($type, 'S3', 3);
    nightClaim($first, '2028-06-10', ClaimKind::Booking);
    nightClaim($second, '2028-06-10', ClaimKind::Booking);
    nightClaim($first, '2028-06-11', ClaimKind::Booking);
    nightClaim($second, '2028-06-11', ClaimKind::Booking);

    $kpis = app(NightAvailability::class)->kpis(
        $property,
        CarbonImmutable::parse('2028-06-10'),
        CarbonImmutable::parse('2028-06-12'),
    );

    expect($kpis['nights_fully_sold'])->toBe(0)
        ->and($kpis['free_room_nights'])->toBe(2)
        ->and($kpis['nights_below_threshold'])->toBe(2)
        ->and($kpis['occupancy_pct'])->toBe(66);
});

test('the night query count does not grow with the number of nights', function (): void {
    $property = Property::factory()->create();
    $type = nightType($property, 'SUITE', 'Suite');
    $room = nightRoom($type, 'S1', 1);
    nightClaim($room, '2028-06-01', ClaimKind::Booking);

    $service = app(NightAvailability::class);
    $short = nightQueryCount(fn () => $service->grid(
        $property,
        CarbonImmutable::parse('2028-06-01'),
        CarbonImmutable::parse('2028-06-02'),
    ));
    $long = nightQueryCount(fn () => $service->grid(
        $property,
        CarbonImmutable::parse('2028-06-01'),
        CarbonImmutable::parse('2028-07-02'),
    ));

    expect($long)->toBe($short)
        ->and($short)->toBeGreaterThan(0);
});

test('a property calendar returns the night grid and rejects more than 62 nights', function (): void {
    $property = Property::factory()->create();
    $type = nightType($property, 'SUITE', 'Suite');
    foreach (range(1, 24) as $number) {
        nightRoom($type, 'R'.$number, $number);
    }

    $started = hrtime(true);
    $response = $this->actingAs(managerUser())
        ->getJson('/api/rms/calendar?from=2028-06-01&to=2028-07-02&property_id='.$property->id)
        ->assertOk()
        ->assertJsonPath('property.id', $property->id)
        ->assertJsonCount(31, 'nights')
        ->assertJsonCount(24, 'rooms')
        ->assertJsonPath('counts.2028-06-01.SUITE.total', 24)
        ->assertJsonPath('counts.2028-06-01.SUITE.free', 24);
    $elapsedMs = (hrtime(true) - $started) / 1_000_000;

    expect($response->json())->toHaveKeys(['rooms', 'counts', 'occupancy', 'kpis'])
        ->and($response->json())->not->toHaveKey('departures')
        ->and($elapsedMs)->toBeLessThan(300);

    $this->actingAs(managerUser())
        ->getJson('/api/rms/calendar?from=2028-06-01&to=2028-08-03&property_id='.$property->id)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

function nightType(Property $property, string $code, string $name, int $sort = 1): RoomType
{
    return RoomType::query()->create([
        'property_id' => $property->id,
        'code' => $code,
        'name' => $name,
        'base_occupancy' => 2,
        'max_occupancy' => 3,
        'max_adults' => 2,
        'max_children' => 1,
        'waitlist_enabled' => false,
        'sort' => $sort,
        'status' => RoomTypeStatus::Active,
    ]);
}

function nightRoom(RoomType $type, string $code, int $sort): Room
{
    return Room::query()->create([
        'property_id' => $type->property_id,
        'room_type_id' => $type->id,
        'code' => $code,
        'label' => 'Room '.$code,
        'sort' => $sort,
        'status' => RoomStatus::Active,
    ]);
}

function nightClaim(
    Room $room,
    string $night,
    ClaimKind $kind,
    ?CarbonInterface $expiresAt = null,
    ?Model $holder = null,
    ?string $group = null,
): void {
    $holder ??= ClaimHolder::query()->create([
        'reference' => (string) Str::uuid(),
        'name' => 'Holder',
    ]);

    RoomNightClaim::query()->create([
        'room_id' => $room->id,
        'night' => $night,
        'holder_type' => $holder->getMorphClass(),
        'holder_id' => $holder->getKey(),
        'kind' => $kind,
        'hold_type' => $kind === ClaimKind::Hold ? HoldType::Web : null,
        'expires_at' => $expiresAt,
        'claim_group' => $group ?? (string) Str::uuid(),
    ]);
}

/**
 * @return array<string, array<string, array{total: int, free: int, held: int, sold: int, blocked: int}>>
 */
function nightBruteCounts(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
{
    $rooms = Room::query()
        ->where('property_id', $property->id)
        ->where('status', RoomStatus::Active)
        ->with('roomType')
        ->orderBy('sort')
        ->orderBy('id')
        ->get();
    $counts = [];
    $night = $from->startOfDay();
    $end = $to->startOfDay();

    while ($night->lt($end)) {
        $key = $night->toDateString();
        $counts[$key] = [];

        foreach ($rooms as $room) {
            $code = $room->roomType->code;
            if (! isset($counts[$key][$code])) {
                $counts[$key][$code] = ['total' => 0, 'free' => 0, 'held' => 0, 'sold' => 0, 'blocked' => 0];
            }

            $counts[$key][$code]['total']++;
            $claim = RoomNightClaim::query()
                ->where('room_id', $room->id)
                ->whereDate('night', $key)
                ->whereNull('released_at')
                ->first();
            $expired = $claim instanceof RoomNightClaim
                && $claim->kind === ClaimKind::Hold
                && $claim->expires_at !== null
                && $claim->expires_at->lt(now());
            $state = (! $claim instanceof RoomNightClaim || $expired)
                ? 'free'
                : match ($claim->kind) {
                    ClaimKind::Hold => 'held',
                    ClaimKind::Booking => 'sold',
                    ClaimKind::Block => 'blocked',
                };
            $counts[$key][$code][$state]++;
        }

        $night = $night->addDay();
    }

    return $counts;
}

function nightQueryCount(Closure $callback): int
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });
    $before = $count;
    $callback();

    return $count - $before;
}
