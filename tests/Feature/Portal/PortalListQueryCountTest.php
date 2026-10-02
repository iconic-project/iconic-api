<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\MainChannel;
use App\Models\Booking;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('the availability list query count does not grow with extra departures', function (): void {
    $agency = approvedAgency();
    $user = agencyUser([], $agency);
    ReservationFixtures::anamaraDeparture('2027-11-07');

    $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/availability')
        ->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/availability')
        ->assertOk();
    $before = count(DB::getQueryLog());

    // actingAs('agency') also made 'agency' the default auth driver for the rest of
    // this test, so HasAuditColumns' bare Auth::id() would otherwise try to stamp
    // created_by with an agency_users id, which the staff users table rejects.
    Auth::shouldUse('web');

    foreach (['2027-11-21', '2027-11-28', '2027-12-05'] as $date) {
        ReservationFixtures::anamaraDeparture($date);
    }

    DB::flushQueryLog();
    $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/availability')
        ->assertOk();
    $after = count(DB::getQueryLog());

    expect($after)->toBe($before);
});

test('the bookings list query count does not grow with extra bookings', function (): void {
    $agency = approvedAgency(['commission_pct' => 10]);
    $user = agencyUser([], $agency);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');

    Booking::factory()->create([
        'reference' => 'ANK-2026-5001',
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Confirmed,
        'total' => 10000,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);

    $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/bookings')
        ->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/bookings')
        ->assertOk();
    $before = count(DB::getQueryLog());

    // See the note in the availability test above — reset the default guard before
    // creating more records so HasAuditColumns doesn't stamp an agency_users id.
    Auth::shouldUse('web');

    foreach (['S2' => '5002', 'S3' => '5003', 'S4' => '5004'] as $cabin => $suffix) {
        Booking::factory()->create([
            'reference' => 'ANK-2026-'.$suffix,
            'departure_id' => $departure->id,
            'cabin_id' => $departure->property->cabins->firstWhere('code', $cabin)?->id,
            'agency_id' => $agency->id,
            'commission_pct' => 10,
            'commission_approved' => true,
            'status' => BookingStatus::Confirmed,
            'total' => 10000,
            'main_channel' => MainChannel::B2BTravelAdvisor,
        ]);
    }

    DB::flushQueryLog();
    $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/bookings')
        ->assertOk()
        ->assertJsonCount(4, 'data');
    $after = count(DB::getQueryLog());

    expect($after)->toBe($before);
});
