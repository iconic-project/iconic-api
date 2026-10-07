<?php

declare(strict_types=1);

use App\Actions\Restrictions\SetStayRestrictions;
use App\Enums\BehaviouralEventName;
use App\Enums\ClaimKind;
use App\Enums\OfferChannel;
use App\Enums\RoomStatus;
use App\Enums\RoomTypeStatus;
use App\Models\Offer;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Engine\EngineFeedVersion;
use App\Services\Inventory\ClaimService;
use App\Support\Engine\QuoteToken;
use App\Support\Stays\StayDates;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\HotelSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-05 12:00:00');
    Cache::flush();
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    $this->seed(DemoUsersSeeder::class);
    $this->seed(HotelSeeder::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('the property feed lists complete room types and hides room numbers', function (): void {
    $property = Property::query()->where('code', 'HTL')->firstOrFail();

    RoomType::query()->create([
        'property_id' => $property->id,
        'code' => 'GAP',
        'name' => 'Incomplete',
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'max_adults' => 2,
        'max_children' => 0,
        'waitlist_enabled' => false,
        'sort' => 9,
        'status' => RoomTypeStatus::Active,
    ]);

    $response = $this->getJson('/api/engine/property')->assertOk();
    $json = $response->json();

    expect($json['property']['code'])->toBe('HTL');
    expect($json['default_rate_plan'])->toBe('BAR');
    expect($json['settings'])->toHaveKeys(['copy', 'guests', 'locale', 'stay', 'availability']);
    expect($json['settings']['stay']['min_nights'])->toBeInt();
    expect($json['settings']['stay']['max_nights'])->toBeInt();
    expect($json['settings']['stay']['max_rooms_per_booking'])->toBeInt();
    expect($json['settings']['availability']['low_availability_threshold'])->toBeInt();
    expect(collect($json['room_types'])->pluck('code')->all())->toBe(['STD', 'TWN', 'FAM', 'STE']);
    expect(collect($json['room_types'])->firstWhere('code', 'TWN')['from_price'])->toBe(220);
    expect(collect($json['room_types'])->firstWhere('code', 'STD')['from_price'])->toBe(250);
    expect(engineKeys($json))->not->toContain('holder');
    expect(json_encode($json))->not->toContain('Room 101');

    $etag = $response->headers->get('ETag');
    $this->getJson('/api/engine/property', ['If-None-Match' => $etag])->assertNotModified();
});

test('the calendar prices a season once and a claim drops availability', function (): void {
    $first = $this->getJson('/api/engine/calendar?from=2026-01&months=1&adults=2&children=0')
        ->assertOk()
        ->json();

    expect($first['nights'])->toHaveCount(31);
    expect($first['nights'][0])->toBe([
        'night' => '2026-01-01',
        'available' => true,
        'from_price' => 90,
        'offers' => [],
        'closed_to_arrival' => false,
        'closed_to_departure' => false,
        'min_stay' => 1,
    ]);
    expect(collect($first['nights'])->firstWhere('night', '2026-01-02')['from_price'])->toBe(90);

    $december = $this->getJson('/api/engine/calendar?from=2026-12&months=1&adults=2&children=0')
        ->assertOk()
        ->json();
    expect(collect($december['nights'])->firstWhere('night', '2026-12-21')['available'])->toBeTrue();

    $version = EngineFeedVersion::current();
    $property = Property::query()->where('code', 'HTL')->firstOrFail();
    $stay = StayDates::forNights('2026-12-21', 1);
    $holder = ClaimHolder::query()->create(['reference' => 'HLD-CAL', 'name' => 'Taken']);

    DB::transaction(function () use ($property, $stay, $holder): void {
        foreach ($property->roomTypes as $type) {
            $count = $type->rooms()->where('status', RoomStatus::Active)->count();

            if ($count < 1) {
                continue;
            }

            app(ClaimService::class)->claimType($stay, $type, $count, $holder, ClaimKind::Booking);
        }
    });

    expect(EngineFeedVersion::current())->toBeGreaterThan($version);

    $second = $this->getJson('/api/engine/calendar?from=2026-12&months=1&adults=2&children=0')
        ->assertOk()
        ->json();
    $night = collect($second['nights'])->firstWhere('night', '2026-12-21');

    expect($night['available'])->toBeFalse();
    expect($night['from_price'])->toBeNull();
    expect(collect($second['nights'])->firstWhere('night', '2026-12-22')['available'])->toBeTrue();
});

test('a three month calendar stays under 400 ms', function (): void {
    $started = hrtime(true);
    $this->getJson('/api/engine/calendar?from=2026-01&months=3&adults=2&children=0')->assertOk();
    $ms = (hrtime(true) - $started) / 1_000_000;

    expect($ms)->toBeLessThan(400);
});

test('availability caps rooms left and returns a reason when the party does not fit', function (): void {
    $json = $this->getJson('/api/engine/availability?check_in=2026-02-02&check_out=2026-02-04&adults=2&rooms=1')
        ->assertOk()
        ->json();

    $std = collect($json['room_types'])->firstWhere('code', 'STD');

    expect($std['bookable'])->toBeTrue();
    expect($std['reasons'])->toBe([]);
    expect($std['rooms_left'])->toBe(4);
    expect($std['quotes'][0]['rate_plan'])->toBe('BAR');
    expect($std['quotes'][0]['total'])->toBe(200);
    expect($std['quotes'][1]['rate_plan'])->toBe('NR');
    expect($std['quotes'][1]['total'])->toBe(180);
    expect(engineKeys($json))->not->toContain('holder');
    expect(json_encode($json))->not->toContain('Room 101');

    $crowded = $this->getJson('/api/engine/availability?check_in=2026-02-02&check_out=2026-02-03&adults=3&rooms=1')
        ->assertOk()
        ->json();

    expect(collect($crowded['room_types'])->firstWhere('code', 'STD')['reasons'])->toBe(['OVER_OCCUPANCY']);

    $property = Property::query()->where('code', 'HTL')->firstOrFail();
    app(SetStayRestrictions::class)->handle([
        'property_id' => $property->id,
        'room_type_ids' => [],
        'from' => '2026-02-02',
        'to' => '2026-02-02',
        'min_stay' => 3,
    ], adminUser());

    $short = $this->getJson('/api/engine/availability?check_in=2026-02-02&check_out=2026-02-03&adults=2&rooms=1')
        ->assertOk()
        ->json();

    expect(collect($short['room_types'])->firstWhere('code', 'STD')['reasons'])->toContain('MIN_STAY:3');

    $this->getJson('/api/engine/availability?check_in=2027-01-05&check_out=2027-01-06&adults=2&rooms=1')
        ->assertOk()
        ->assertJsonPath('room_types.0.reasons.0', 'NO_RATE');
});

test('a stay quote is signed and an unbookable stay is refused', function (): void {
    $quoted = $this->postJson('/api/engine/quote', [
        'check_in' => '2026-02-02',
        'check_out' => '2026-02-04',
        'rooms' => [[
            'room_type' => 'STD',
            'adults' => 2,
            'child_ages' => [],
            'rate_plan' => 'BAR',
        ]],
    ])->assertOk()->json();

    expect($quoted['total'])->toBe(200);
    expect($quoted['deposit'])->toBe(60);
    expect($quoted['nights'])->toBe(2);

    $token = QuoteToken::open($quoted['quote_token']);

    expect($token['check_in'])->toBe('2026-02-02');
    expect($token['total'])->toBe(200);
    expect($token['rates_version_id'])->toBe($quoted['rates_version_id']);

    $online = $this->postJson('/api/engine/quote', [
        'check_in' => '2026-02-02',
        'check_out' => '2026-02-04',
        'rooms' => [[
            'room_type' => 'STD',
            'adults' => 2,
            'child_ages' => [],
            'rate_plan' => 'BAR',
            'online_deposit' => true,
        ]],
    ])->assertOk()->json();

    expect($online['check_in'])->toBe('2026-02-02');
    expect($online['check_out'])->toBe('2026-02-04');
    expect($online['total'])->toBeLessThan($quoted['total']);
    expect($online['deposit'])->toBeLessThan($quoted['deposit']);

    $this->postJson('/api/engine/quote', [
        'check_in' => '2026-02-02',
        'check_out' => '2026-02-04',
        'rooms' => [[
            'room_type' => 'STD',
            'adults' => 3,
            'child_ages' => [],
            'rate_plan' => 'BAR',
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['rooms.0.adults']);
});

test('a promo is checked against check in', function (): void {
    Offer::factory()->live()->promo()->create([
        'code' => 'STAY10',
        'channel' => OfferChannel::D2C,
        'stay_from' => '2026-10-01',
        'stay_to' => '2026-12-31',
        'price_line' => 'Stay 10',
    ]);

    $this->postJson('/api/engine/promo/check', [
        'code' => 'STAY10',
        'check_in' => '2026-10-10',
        'check_out' => '2026-10-12',
        'room_type' => 'STD',
    ])->assertOk()->assertJson([
        'valid' => true,
        'line' => 'Stay 10',
        'applies_to' => ['STD'],
    ]);

    $this->postJson('/api/engine/promo/check', [
        'code' => 'STAY10',
        'check_in' => '2027-01-02',
        'check_out' => '2027-01-04',
    ])->assertOk()->assertJsonPath('valid', false);
});

test('search and room type events are accepted', function (): void {
    $this->postJson('/api/engine/events', [
        'session_id' => engineSessionId(),
        'events' => [
            engineEvent(BehaviouralEventName::SearchPerformed->value, [
                'check_in' => '2026-02-02',
                'check_out' => '2026-02-04',
                'adults' => 2,
                'children' => 0,
                'rooms' => 1,
            ]),
            engineEvent(BehaviouralEventName::RoomTypeViewed->value, [
                'room_type' => 'STD',
            ]),
        ],
    ])->assertOk();
});

test('property reads share the engine rate limit', function (): void {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/engine/property')->assertOk();
    }

    $this->getJson('/api/engine/property')->assertStatus(429);
});
