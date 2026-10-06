<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\ChannelOfOrigin;
use App\Enums\ChannelOfOriginGroup;
use App\Enums\ClaimKind;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use App\Services\Inventory\ClaimService;
use App\Support\Metrics\HotelKpis;
use App\Support\Stays\StayDates;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-28 15:00:00', 'UTC'));
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('hotel kpis match the hand-computed stay, including a month split, a block, a no-show and a cancellation', function (): void {
    $property = Property::factory()->create();
    $rooms = collect([1, 2])->map(fn (int $number): Room => Room::factory()->create([
        'property_id' => $property->id,
        'code' => 'R'.$number,
        'label' => 'Room '.$number,
        'sort' => $number,
    ]));
    $roomTypeId = (int) $rooms->first()?->room_type_id;
    $sold = Booking::factory()->create([
        'property_id' => $property->id,
        'room_id' => $rooms[0]->id,
        'room_type_id' => $roomTypeId,
        'check_in' => '2026-10-30',
        'check_out' => '2026-11-02',
        'nights' => 3,
        'status' => BookingStatus::Confirmed,
        'channel_of_origin' => ChannelOfOrigin::HotelWebsiteInquiry,
        'total' => 600,
        'sold_on' => '2026-10-28',
        'created_at' => '2026-10-28 18:00:00',
        'night_lines' => [
            ['night' => '2026-10-30', 'season' => 'LOW', 'base' => 100, 'extras' => 0, 'single' => 0, 'dow' => 0, 'supplements' => 0, 'plan_adjust' => 0, 'total' => 100],
            ['night' => '2026-10-31', 'season' => 'LOW', 'base' => 200, 'extras' => 0, 'single' => 0, 'dow' => 0, 'supplements' => 0, 'plan_adjust' => 0, 'total' => 200],
            ['night' => '2026-11-01', 'season' => 'HIGH', 'base' => 300, 'extras' => 0, 'single' => 0, 'dow' => 0, 'supplements' => 0, 'plan_adjust' => 0, 'total' => 300],
        ],
    ]);
    Booking::factory()->create([
        'property_id' => $property->id,
        'room_id' => $rooms[0]->id,
        'room_type_id' => $roomTypeId,
        'check_in' => '2026-10-30',
        'check_out' => '2026-10-31',
        'nights' => 1,
        'status' => BookingStatus::NoShow,
        'total' => 100,
        'sold_on' => '2026-10-01',
    ]);
    Booking::factory()->create([
        'property_id' => $property->id,
        'room_id' => $rooms[0]->id,
        'room_type_id' => $roomTypeId,
        'check_in' => '2026-10-30',
        'check_out' => '2026-10-31',
        'nights' => 1,
        'status' => BookingStatus::Cancelled,
        'total' => 100,
        'sold_on' => '2026-10-01',
    ]);
    $block = Booking::factory()->create([
        'property_id' => $property->id,
        'room_id' => $rooms[1]->id,
        'room_type_id' => $roomTypeId,
        'check_in' => '2026-01-01',
        'check_out' => '2026-01-02',
        'nights' => 1,
        'status' => BookingStatus::Released,
        'total' => 0,
        'sold_on' => '2026-01-01',
    ]);

    DB::transaction(function () use ($rooms, $sold, $block): void {
        app(ClaimService::class)->claim(
            StayDates::of('2026-10-30', '2026-11-02'),
            collect([$rooms[0]]),
            $sold,
            ClaimKind::Booking,
        );
        app(ClaimService::class)->claim(
            StayDates::of('2026-10-30', '2026-10-31'),
            collect([$rooms[1]]),
            $block,
            ClaimKind::Block,
        );
    });

    $kpis = app(HotelKpis::class);
    $range = $kpis->measure('2026-10-30', '2026-11-02', '2026-10-30', 7, $property->id, $roomTypeId, null);

    expect($range['room_nights_available'])->toBe(5)
        ->and($range['room_nights_sold'])->toBe(3)
        ->and($range['occupancy'])->toBe('60.0')
        ->and($range['room_revenue'])->toBe(600)
        ->and($range['adr'])->toBe(200)
        ->and($range['revpar'])->toBe(120)
        ->and($range['pickup_room_nights'])->toBe(3)
        ->and($range['average_length_of_stay'])->toBe('3.0')
        ->and($range['lead_time_days'])->toBe(2)
        ->and($range['cancellation_rate'])->toBe('33.3')
        ->and($range['no_show_rate'])->toBe('50.0')
        ->and($range['legacy_room_revenue'])->toBeFalse()
        ->and($range['channel_mix'])->toBe([
            [
                'channel' => ChannelOfOrigin::HotelWebsiteInquiry->value,
                'bookings' => 1,
                'room_nights' => 3,
                'room_revenue' => 600,
            ],
        ])
        ->and($range['nights'][0])->toMatchArray([
            'night' => '2026-10-30',
            'room_nights_sold' => 1,
            'room_nights_available' => 1,
            'occupancy' => '100.0',
            'room_revenue' => 100,
            'adr' => 100,
            'revpar' => 100,
        ])
        ->and($range['nights'][2])->toMatchArray([
            'night' => '2026-11-01',
            'room_revenue' => 300,
            'adr' => 300,
            'revpar' => 150,
        ]);

    $october = $kpis->measure('2026-10-30', '2026-11-01', '2026-10-30', 7, $property->id);

    expect($october['room_nights_sold'])->toBe(2)
        ->and($october['room_nights_available'])->toBe(3)
        ->and($october['room_revenue'])->toBe(300)
        ->and($october['adr'])->toBe(150)
        ->and($october['revpar'])->toBe(100)
        ->and($october['occupancy'])->toBe('66.7');

    $otherChannel = $kpis->measure(
        '2026-10-30',
        '2026-11-02',
        '2026-10-30',
        7,
        $property->id,
        null,
        ChannelOfOriginGroup::Marketing,
    );

    expect($otherChannel['room_nights_sold'])->toBe(0)
        ->and($otherChannel['room_nights_available'])->toBe(5)
        ->and($otherChannel['room_revenue'])->toBe(0)
        ->and($otherChannel['adr'])->toBeNull()
        ->and($otherChannel['occupancy'])->toBe('0.0');
});

test('a booking without night lines splits its total across the stay and is flagged', function (): void {
    $property = Property::factory()->create();
    $room = Room::factory()->create([
        'property_id' => $property->id,
        'code' => 'L1',
        'sort' => 1,
    ]);
    $booking = Booking::factory()->create([
        'property_id' => $property->id,
        'room_id' => $room->id,
        'room_type_id' => $room->room_type_id,
        'check_in' => '2026-12-01',
        'check_out' => '2026-12-03',
        'nights' => 2,
        'status' => BookingStatus::Confirmed,
        'total' => 400,
        'night_lines' => null,
        'sold_on' => '2026-12-01',
    ]);

    DB::transaction(fn () => app(ClaimService::class)->claim(
        StayDates::of('2026-12-01', '2026-12-03'),
        collect([$room]),
        $booking,
        ClaimKind::Booking,
    ));

    $measured = app(HotelKpis::class)->measure('2026-12-01', '2026-12-03', '2026-12-01', 7, $property->id);

    expect($measured['legacy_room_revenue'])->toBeTrue()
        ->and($measured['room_revenue'])->toBe(400)
        ->and($measured['room_nights_sold'])->toBe(2)
        ->and($measured['adr'])->toBe(200)
        ->and($measured['nights'][0]['room_revenue'])->toBe(200)
        ->and($measured['nights'][1]['room_revenue'])->toBe(200);
});

test('the occupancy report file lists each night and a retired departure report is not offered', function (): void {
    $admin = User::factory()->create([
        'role_id' => Role::query()->where('slug', 'admin')->value('id'),
    ]);
    $property = Property::factory()->create();
    $room = Room::factory()->create([
        'property_id' => $property->id,
        'code' => 'A1',
        'sort' => 1,
    ]);
    $booking = Booking::factory()->create([
        'reference' => 'ANK-KPI-0001',
        'property_id' => $property->id,
        'room_id' => $room->id,
        'room_type_id' => $room->room_type_id,
        'check_in' => '2026-10-30',
        'check_out' => '2026-10-31',
        'nights' => 1,
        'status' => BookingStatus::Confirmed,
        'total' => 80,
        'night_lines' => [
            ['night' => '2026-10-30', 'season' => 'LOW', 'base' => 80, 'extras' => 0, 'single' => 0, 'dow' => 0, 'supplements' => 0, 'plan_adjust' => 0, 'total' => 80],
        ],
        'sold_on' => '2026-10-30',
    ]);

    DB::transaction(fn () => app(ClaimService::class)->claim(
        StayDates::of('2026-10-30', '2026-10-31'),
        collect([$room]),
        $booking,
        ClaimKind::Booking,
    ));

    $catalogue = $this->actingAs($admin)->getJson('/api/rms/reports')->assertOk()->json('data');
    $keys = collect($catalogue)->pluck('key');
    expect($keys)->toContain('occupancy-revenue', 'pace', 'arrivals-forecast')
        ->and($keys)->not->toContain('occupancy', 'revenue-monthly', 'overdue', 'forecast-30-day');

    $this->actingAs($admin)
        ->postJson('/api/rms/reports/occupancy/runs', ['from' => '2026-10-30', 'to' => '2026-10-30'])
        ->assertNotFound();

    $run = $this->actingAs($admin)
        ->postJson('/api/rms/reports/occupancy-revenue/runs', [
            'from' => '2026-10-30',
            'to' => '2026-10-30',
            'property' => $property->id,
        ])
        ->assertCreated()
        ->json('data');

    expect($run['status'])->toBe('READY');

    $csv = $this->actingAs($admin)
        ->get('/api/rms/reports/runs/'.$run['id'].'/file/csv')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('night,room_nights_sold,room_nights_available,occupancy,room_revenue,adr,revpar')
        ->and($csv)->toContain('2026-10-30,1,1,100.0,80,80,80');

    $arrivals = $this->actingAs($admin)
        ->postJson('/api/rms/reports/arrivals-forecast/runs', [
            'from' => '2026-10-30',
            'to' => '2026-10-30',
            'property' => $property->id,
        ])
        ->assertCreated()
        ->json('data');

    $arrivalCsv = $this->actingAs($admin)
        ->get('/api/rms/reports/runs/'.$arrivals['id'].'/file/csv')
        ->assertOk()
        ->streamedContent();

    expect($arrivalCsv)->toContain('check_in,booking,room_type,nights,status,channel')
        ->and($arrivalCsv)->toContain('ANK-KPI-0001')
        ->and($arrivalCsv)->toContain('CONFIRMED');

    $crm = User::factory()->create([
        'role_id' => Role::factory()->create(['permissions' => [Permission::PanelCrm]])->id,
    ]);

    $this->actingAs($crm)->getJson('/api/rms/hotel-kpis')->assertForbidden();
    $this->actingAs($admin)->getJson('/api/rms/hotel-kpis')->assertOk()
        ->assertJsonPath('periods.0.key', 'this_month')
        ->assertJsonPath('periods.1.key', 'next_30')
        ->assertJsonPath('periods.2.key', 'next_90')
        ->assertJsonStructure([
            'periods' => [
                '*' => [
                    'kpis' => ['occupancy', 'adr', 'revpar'],
                ],
            ],
        ]);
});
