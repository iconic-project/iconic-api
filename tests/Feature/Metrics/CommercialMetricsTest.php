<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\ChannelOfOrigin;
use App\Enums\ClaimKind;
use App\Enums\GuestResponseSource;
use App\Enums\Permission;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\BookingExtra;
use App\Models\Departure;
use App\Models\Guest;
use App\Models\GuestResponse;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Inventory\Availability;
use App\Services\Inventory\ClaimService;
use App\Services\Inventory\LegacyDepartureClaims;
use App\Support\BusinessTime;
use App\Support\Commissions\CommissionKpis;
use App\Support\GuestExperience\NpsDashboard;
use App\Support\Payments\PaymentsKpis;
use App\Support\Rounding;
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

test('commercial metrics match the calendar, payments, agencies and guest experience', function (): void {
    $admin = adminUser();
    $from = '2027-11-01';
    $to = '2027-11-30';
    $agency = Agency::factory()->create();
    $cabinDeparture = ReservationFixtures::anamaraDeparture('2027-11-07');
    $charterDeparture = ReservationFixtures::anamaraDeparture('2027-11-14');
    $saleAt = '2027-10-01 12:00:00';

    $cabin = Booking::factory()->create([
        'departure_id' => $cabinDeparture->id,
        'room_id' => $cabinDeparture->property->cabins->firstWhere('code', 'S1')?->id,
        'owner_id' => $admin->id,
        'status' => BookingStatus::Confirmed,
        'total' => 26600,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'channel_of_origin' => ChannelOfOrigin::HotelBookingEngine,
    ]);
    $blocked = Booking::factory()->create([
        'departure_id' => $cabinDeparture->id,
        'room_id' => $cabinDeparture->property->cabins->firstWhere('code', 'S2')?->id,
        'owner_id' => $admin->id,
        'status' => BookingStatus::Confirmed,
        'total' => 10000,
        'agency_id' => $agency->id,
        'commission_pct' => 20,
        'commission_approved' => false,
        'channel_of_origin' => ChannelOfOrigin::HotelBookingEngine,
    ]);
    $charter = Booking::factory()->create([
        'departure_id' => $charterDeparture->id,
        'room_id' => null,
        'owner_id' => $admin->id,
        'type' => BookingType::Charter,
        'status' => BookingStatus::Confirmed,
        'total' => 199500,
        'channel_of_origin' => ChannelOfOrigin::TravelAdvisor,
    ]);

    foreach ([$cabin, $blocked, $charter] as $booking) {
        $booking->forceFill(['created_at' => $saleAt, 'updated_at' => $saleAt])->save();
    }

    DB::transaction(function () use ($cabinDeparture, $cabin, $blocked, $charterDeparture, $charter): void {
        $claims = app(LegacyDepartureClaims::class);
        $claims->claim(
            $cabinDeparture,
            collect([$cabinDeparture->property->cabins->firstWhere('code', 'S1')]),
            $cabin,
            ClaimKind::Booking,
        );
        $claims->claim(
            $cabinDeparture,
            collect([$cabinDeparture->property->cabins->firstWhere('code', 'S2')]),
            $blocked,
            ClaimKind::Booking,
        );
        $claims->claim($charterDeparture, $charterDeparture->property->cabins, $charter, ClaimKind::Booking);
    });

    BookingExtra::factory()->create([
        'booking_id' => $cabin->id,
        'qty' => 1,
        'rate_usd' => 420,
    ]);
    Payment::factory()->create([
        'booking_id' => $cabin->id,
        'amount' => 2660,
        'paid_at' => '2027-11-07',
    ]);

    $lead = Guest::factory()->create([
        'booking_id' => $cabin->id,
        'position' => 1,
        'is_lead' => true,
        'first_name' => 'QX-METRIC-GUEST',
        'last_name' => 'Hidden',
        'nationality' => 'GB',
        'email' => 'qx-metric-guest@iconic.test',
    ]);
    $companion = Guest::factory()->create([
        'booking_id' => $cabin->id,
        'position' => 2,
        'is_lead' => false,
        'first_name' => 'QX-METRIC-COMPANION',
        'nationality' => 'EC',
    ]);
    $respondedAt = BusinessTime::calendarDay('2027-11-07')->addHours(12)->utc();
    GuestResponse::query()->create([
        'guest_id' => $lead->id,
        'booking_id' => $cabin->id,
        'score' => 9,
        'source' => GuestResponseSource::GuestLink,
        'responded_at' => $respondedAt,
    ]);
    GuestResponse::query()->create([
        'guest_id' => $companion->id,
        'booking_id' => $cabin->id,
        'score' => 6,
        'source' => GuestResponseSource::GuestLink,
        'responded_at' => $respondedAt,
    ]);

    $response = test()->actingAs($admin)
        ->getJson('/api/rms/metrics?from='.$from.'&to='.$to)
        ->assertOk();

    assertNoSensitiveFields($response);
    $encoded = (string) json_encode($response->json());
    expect($encoded)->not->toContain('QX-METRIC-GUEST')
        ->and($encoded)->not->toContain('qx-metric-guest@iconic.test');

    $metrics = $response->json('metrics');
    expect($metrics)->toHaveKeys([
        'occupancy', 'revpab', 'adr', 'lead_time', 'channel_mix', 'nationality_mix', 'nps', 'commissions', 'cash',
    ]);

    foreach ($metrics as $metric) {
        expect($metric['definition'])->toHaveKeys(['sentence', 'filters_on', 'excludes'])
            ->and($metric['definition']['sentence'])->not->toBe('');
    }

    $departures = Departure::query()
        ->with(['property.cabins', 'itinerary'])
        ->whereDate('date', '>=', $from)
        ->whereDate('date', '<=', $to)
        ->get();
    $snapshots = app(Availability::class)->forDepartures($departures);
    $calendarSold = 0;
    $calendarSellable = 0;

    foreach ($departures as $departure) {
        $counts = $snapshots[$departure->id]->counts;
        $row = collect($metrics['occupancy']['departures'])->firstWhere('id', $departure->id);
        $sold = $counts['sold'];
        $sellable = $counts['sold'] + $counts['held'] + $counts['free'];

        if ($departure->id === $charterDeparture->id) {
            $sold = $departure->property->cabins->count();
        }

        expect($row['sold_berths'])->toBe($sold)
            ->and($row['sellable_berths'])->toBe($sellable);

        $calendarSold += $sold;
        $calendarSellable += $sellable;
    }

    expect($metrics['occupancy']['sold_berths'])->toBe($calendarSold)
        ->and($metrics['occupancy']['sellable_berths'])->toBe($calendarSellable)
        ->and($metrics['occupancy']['occupancy'])->toBe('0.6111');

    $cruise = 26600 + 10000 + 199500;
    expect($metrics['revpab']['cruise_revenue'])->toBe($cruise)
        ->and($metrics['revpab']['cruise_revenue'])->not->toBe($cabin->chargesTotal())
        ->and($metrics['revpab']['revpab'])->toBe(Rounding::halfUp($cruise / $calendarSellable))
        ->and($metrics['adr']['adr'])->toBe(Rounding::halfUp($cruise / $calendarSold))
        ->and($metrics['adr']['berths_sold'])->toBe($calendarSold);

    expect($metrics['lead_time']['bookings'])->toBe(3)
        ->and($metrics['lead_time']['average_days'])->toBe('39.3')
        ->and($metrics['lead_time']['median_days'])->toBe('37.0');

    $direct = collect($metrics['channel_mix']['rows'])->firstWhere('channel', ChannelOfOrigin::HotelBookingEngine->value);
    $trade = collect($metrics['channel_mix']['rows'])->firstWhere('channel', ChannelOfOrigin::TravelAdvisor->value);
    expect($direct['group'])->toBe('Direct')
        ->and($direct['bookings'])->toBe(2)
        ->and($direct['revenue'])->toBe(36600)
        ->and($trade['group'])->toBe('Trade')
        ->and($trade['revenue'])->toBe(199500);

    expect($metrics['nationality_mix']['rows'])->toBe([
        ['country_code' => 'EC', 'guests' => 1],
        ['country_code' => 'GB', 'guests' => 1],
    ]);

    $nps = app(NpsDashboard::class)->present($from, $to);
    expect($metrics['nps']['average_score'])->toBe($nps['kpis']['average_score'])
        ->and($metrics['nps']['responses'])->toBe($nps['kpis']['responses'])
        ->and($metrics['nps']['promoters'])->toBe(1)
        ->and($metrics['nps']['passives'])->toBe(0)
        ->and($metrics['nps']['detractors'])->toBe(1);

    $commission = CommissionKpis::forApproved($from, $to, app(CurrentConfig::class)->businessRules());
    expect($metrics['commissions']['payable'])->toBe($commission['commission_payable'])
        ->and($metrics['commissions']['paid'])->toBe($commission['commission_paid'])
        ->and($metrics['commissions']['earned'])->toBe($commission['commission_accrued'])
        ->and($metrics['commissions']['blocked'])->toBe(2000);

    $cash = PaymentsKpis::for($admin, $from, $to);
    expect($metrics['cash']['collected'])->toBe($cash['collected'])
        ->and($metrics['cash']['pending'])->toBe($cash['pending'])
        ->and($metrics['cash']['overdue'])->toBe($cash['overdue_amount'])
        ->and($metrics['cash']['collected'])->toBe(2660)
        ->and($metrics['cash']['deposit_share_pct'])->toBe(100);

    $emptyFrom = '2031-04-01';
    $emptyTo = '2031-04-07';
    $empty = test()->actingAs($admin)
        ->getJson('/api/rms/metrics?from='.$emptyFrom.'&to='.$emptyTo)
        ->assertOk()
        ->json('metrics');
    $emptyCash = PaymentsKpis::for($admin, $emptyFrom, $emptyTo);
    $emptyCommission = CommissionKpis::forApproved($emptyFrom, $emptyTo, app(CurrentConfig::class)->businessRules());
    $emptyNps = app(NpsDashboard::class)->present($emptyFrom, $emptyTo);

    expect($empty['occupancy']['sold_berths'])->toBe(0)
        ->and($empty['occupancy']['sellable_berths'])->toBe(0)
        ->and($empty['cash']['collected'])->toBe($emptyCash['collected'])
        ->and($empty['cash']['pending'])->toBe($emptyCash['pending'])
        ->and($empty['cash']['overdue'])->toBe($emptyCash['overdue_amount'])
        ->and($empty['commissions']['payable'])->toBe($emptyCommission['commission_payable'])
        ->and($empty['commissions']['paid'])->toBe($emptyCommission['commission_paid'])
        ->and($empty['nps']['average_score'])->toBe($emptyNps['kpis']['average_score'])
        ->and($empty['nps']['responses'])->toBe($emptyNps['kpis']['responses']);
});

test('the metrics query count stays flat as bookings grow', function (): void {
    $admin = adminUser();
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');
    $url = '/api/rms/metrics?from=2027-11-01&to=2027-11-30';
    test()->actingAs($admin)->getJson($url)->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->actingAs($admin)->getJson($url)->assertOk();
    $before = count(DB::getQueryLog());

    $extra = Booking::factory()->create([
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S3')?->id,
        'status' => BookingStatus::Confirmed,
        'total' => 28000,
    ]);
    DB::transaction(function () use ($departure, $extra): void {
        app(LegacyDepartureClaims::class)->claim(
            $departure,
            collect([$departure->property->cabins->firstWhere('code', 'S3')]),
            $extra,
            ClaimKind::Booking,
        );
    });

    DB::flushQueryLog();
    test()->actingAs($admin)->getJson($url)->assertOk();
    expect(count(DB::getQueryLog()))->toBe($before);
});

test('metrics require panel.rms and a window', function (): void {
    $role = Role::factory()->create([
        'permissions' => [Permission::PanelCrm],
    ]);
    $crmOnly = User::factory()->create(['role_id' => $role->id]);

    test()->getJson('/api/rms/metrics?from=2027-11-01&to=2027-11-30')->assertUnauthorized();
    test()->actingAs($crmOnly)
        ->getJson('/api/rms/metrics?from=2027-11-01&to=2027-11-30')
        ->assertForbidden();
    test()->actingAs(adminUser())
        ->getJson('/api/rms/metrics')
        ->assertStatus(422);
});
