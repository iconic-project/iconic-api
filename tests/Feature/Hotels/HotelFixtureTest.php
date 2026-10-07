<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Content\Completeness;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\HotelSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Schema;

test('the hotel fixture keeps occupancy, rooms, seasons and quote arithmetic consistent', function (): void {
    /** @var list<array<string, mixed>> $types */
    $types = hotelFixture('room_types');
    /** @var list<array<string, mixed>> $rooms */
    $rooms = hotelFixture('rooms');
    /** @var list<array{code: string, from: string, to: string}> $seasons */
    $seasons = hotelFixture('seasons');
    /** @var list<array<string, mixed>> $quotes */
    $quotes = hotelFixture('reference_quotes');
    /** @var list<array<string, mixed>> $bookings */
    $bookings = hotelFixture('bookings');
    /** @var list<array<string, mixed>> $restrictions */
    $restrictions = hotelFixture('restrictions');
    /** @var list<array<string, mixed>> $plans */
    $plans = hotelFixture('rate_plans');

    expect(hotelFixture('meta.demo'))->toBeTrue();
    expect(hotelFixture('property.code'))->toBe('HTL');
    expect($types)->toHaveCount(4);
    expect($rooms)->toHaveCount(24);
    expect($quotes)->toHaveCount(8);
    expect($bookings)->toHaveCount(30);

    $typeCodes = [];

    foreach ($types as $type) {
        $typeCodes[] = $type['code'];
        expect($type['base_occupancy'])->toBeGreaterThanOrEqual(1);
        expect($type['base_occupancy'])->toBeLessThanOrEqual($type['max_occupancy']);
        expect($type['max_adults'])->toBeGreaterThanOrEqual($type['base_occupancy']);
        expect($type['max_adults'])->toBeLessThanOrEqual($type['max_occupancy']);
        expect($type['max_children'])->toBeGreaterThanOrEqual(0);
        expect($type['max_children'])->toBeLessThan($type['max_occupancy']);
    }

    expect($typeCodes)->toBe(['STD', 'TWN', 'FAM', 'STE']);
    expect(collect($types)->firstWhere('code', 'FAM'))->toMatchArray([
        'base_occupancy' => 2,
        'max_occupancy' => 4,
        'max_children' => 2,
    ]);

    $counts = array_count_values(array_column($rooms, 'room_type'));
    expect($counts)->toBe(['STD' => 10, 'TWN' => 6, 'FAM' => 5, 'STE' => 3]);
    expect(array_column($rooms, 'code'))->toBe([
        '101', '102', '103', '104', '105', '106', '107', '108',
        '201', '202', '203', '204', '205', '206', '207', '208',
        '301', '302', '303', '304', '305', '306', '307', '308',
    ]);

    foreach ($rooms as $room) {
        expect($typeCodes)->toContain($room['room_type']);
    }

    foreach ($seasons as $index => $season) {
        expect($season['from'] <= $season['to'])->toBeTrue();

        foreach (array_slice($seasons, $index + 1) as $other) {
            $overlaps = $season['from'] <= $other['to'] && $other['from'] <= $season['to'];
            expect($overlaps)->toBeFalse();
        }
    }

    expect(collect($plans)->where('default', true))->toHaveCount(1);
    expect(collect($restrictions)->where('stop_sell', true))->toHaveCount(1);
    expect(collect($restrictions)->where('min_stay', 3))->toHaveCount(1);
    expect(collect($restrictions)->where('closed_to_arrival', true))->toHaveCount(1);

    $weekdays = [];
    $lengths = [];

    $typeByCode = collect($types)->keyBy('code');

    foreach ($bookings as $booking) {
        $type = $typeByCode[$booking['room_type']];
        $lengths[] = $booking['nights'];
        $weekdays[] = CarbonImmutable::parse($booking['check_in'])->dayOfWeekIso;
        expect($typeCodes)->toContain($booking['room_type']);
        expect(array_column($rooms, 'code'))->toContain($booking['room']);
        expect($booking['adults'] + $booking['children'])->toBeLessThanOrEqual($type['max_occupancy']);
        expect($booking['adults'])->toBeLessThanOrEqual($type['max_adults']);
        expect($booking['children'])->toBeLessThanOrEqual($type['max_children']);
    }

    expect(min($lengths))->toBe(1);
    expect(max($lengths))->toBe(14);
    expect(collect($weekdays)->unique()->sort()->values()->all())->toBe([1, 2, 3, 4, 5, 6, 7]);

    $group = collect($bookings)->where('group', 'GRP-001');
    expect($group)->toHaveCount(3);
    expect($group->pluck('check_in')->unique())->toHaveCount(3);
    expect($group->pluck('room')->unique())->toHaveCount(3);

    $byRoom = collect($bookings)->groupBy('room');
    $touches = 0;

    foreach ($byRoom as $stays) {
        $ordered = $stays->sortBy('check_in')->values();

        for ($index = 0; $index < $ordered->count(); $index++) {
            $checkOut = CarbonImmutable::parse($ordered[$index]['check_in'])->addDays($ordered[$index]['nights'])->toDateString();

            if (! isset($ordered[$index + 1])) {
                continue;
            }

            $nextIn = $ordered[$index + 1]['check_in'];
            expect($nextIn >= $checkOut)->toBeTrue();

            if ($nextIn === $checkOut) {
                $touches++;
            }
        }
    }

    expect($touches)->toBe(2);

    foreach ($quotes as $quote) {
        $lineTotal = array_sum(array_column($quote['expected_lines'], 'amount'));
        expect($lineTotal)->toBe($quote['expected_total']);

        $left = explode('=', $quote['working'], 2)[0];
        preg_match_all('/-?\d+/', $left, $matches);
        $workingTotal = array_sum(array_map(intval(...), $matches[0]));
        expect($workingTotal)->toBe($quote['expected_total']);
    }
});

test('the hotel seeder is idempotent and bookings have no departure', function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    $this->seed(DemoUsersSeeder::class);
    $this->seed(HotelSeeder::class);
    $this->seed(HotelSeeder::class);

    $property = Property::query()->where('code', 'HTL')->firstOrFail();

    expect(Property::query()->where('code', 'HTL')->count())->toBe(1);
    expect(RoomType::query()->where('property_id', $property->id)->count())->toBe(4);
    expect(Room::query()->where('property_id', $property->id)->count())->toBe(24);
    expect($property->name)->toBe('Hotel Demo');
    expect(Completeness::forProperty($property)->pct)->toBe(100);

    foreach (RoomType::query()->where('property_id', $property->id)->get() as $type) {
        expect(Completeness::forRoomType($type)->pct)->toBe(100);
        expect(Completeness::engineVisible($type))->toBeTrue();
    }
    expect(Booking::query()->count())->toBe(29);
    expect(Schema::hasColumn('bookings', 'departure_id'))->toBeFalse();

    $group = Booking::query()->where('reference', 'HTL-001')->firstOrFail();
    expect($group->status)->toBe(BookingStatus::Confirmed);
    expect($group->group?->reference)->toBe('GRP-001');
    expect(Booking::query()->where('group_id', $group->group_id)->count())->toBe(3);

    $paid = Booking::query()->where('reference', 'HTL-003')->firstOrFail();
    expect($paid->status)->toBe(BookingStatus::FullyPaid);
    expect($paid->payments()->count())->toBeGreaterThan(0);

    expect(Booking::query()->where('request_reference', 'HTL-008')->firstOrFail()->status)->toBe(BookingStatus::Requested);
    expect(Booking::query()->where('reference', 'HTL-011')->firstOrFail()->status)->toBe(BookingStatus::CheckedOut);
    expect(Booking::query()->where('reference', 'HTL-016')->firstOrFail()->status)->toBe(BookingStatus::Cancelled);
    expect(Booking::query()->where('reference', 'HTL-019')->firstOrFail()->status)->toBe(BookingStatus::InHouse);
    expect(Booking::query()->where('reference', 'HTL-026')->firstOrFail()->status)->toBe(BookingStatus::FullyPaid);
    expect(Booking::query()->where('reference', 'HTL-029')->exists())->toBeFalse();
});

test('hotel seed writes the hotel', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Property::query()->pluck('code')->all())->toBe(['HTL']);
    expect(RoomType::query()->count())->toBe(4);
    expect(Room::query()->count())->toBe(24);
    expect(Booking::query()->where('reference', 'HTL-001')->exists())->toBeTrue();
});
