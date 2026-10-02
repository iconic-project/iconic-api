<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\MainChannel;
use App\Models\Booking;
use App\Models\CommissionPayout;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('the portal payloads match the RMS preview figures exactly', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-12-21 18:00:00', 'UTC'));

    $agency = approvedAgency(['commission_pct' => 10]);
    $user = agencyUser([], $agency);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');

    $paid = Booking::factory()->create([
        'reference' => 'ANK-2026-7001',
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Completed,
        'total' => 23275,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);
    CommissionPayout::query()->create([
        'booking_id' => $paid->id,
        'amount' => $paid->commissionAmount(),
        'paid_on' => '2027-12-21',
        'bank_reference' => 'WIRE-100',
    ]);

    $open = Booking::factory()->create([
        'reference' => 'ANK-2026-7002',
        'departure_id' => $departure->id,
        'room_id' => $departure->property->cabins->firstWhere('code', 'S2')?->id,
        'agency_id' => $agency->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Confirmed,
        'total' => 10000,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);

    $preview = $this->actingAs(managerUser())
        ->getJson('/api/rms/agencies/'.$agency->id.'/portal-preview')
        ->assertOk()
        ->json();

    // A staff actingAs() leaves the 'web' guard "logged in" in memory for the rest
    // of the test; Sanctum's AuthenticateSession middleware (config('sanctum.guard'))
    // only ever checks 'web', so a later agency request on a stateful origin trips a
    // false password-hash mismatch against that stale staff session. Forgetting the
    // cached guards forces a clean resolution before switching to the agency actor.
    Auth::forgetGuards();

    $portalRates = $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/rates')
        ->assertOk()
        ->json('data');

    expect($portalRates)->toBe($preview['net_rates']);

    $portalBookings = $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/bookings')
        ->assertOk()
        ->json('data');

    $portalCommissions = $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/commissions')
        ->assertOk()
        ->json('data');

    $previewBookingsByReference = collect($preview['bookings'])->keyBy('reference');
    $previewCommissionsByReference = collect($preview['commissions'])->keyBy('reference');

    foreach ($portalBookings as $booking) {
        $expected = $previewBookingsByReference[$booking['reference']];
        expect($booking['net_due'])->toBe($expected['net_due']);
        expect($booking['departure_date'])->toBe($expected['departure_date']);
        expect($booking['status'])->toBe($expected['status']);
        expect($booking['lead_guest'])->toBe($expected['lead_guest']);
    }

    foreach ($portalCommissions as $commission) {
        $expected = $previewCommissionsByReference[$commission['reference']];
        expect($commission['rate'])->toBe($expected['rate']);
        expect($commission['commission_amount'])->toBe($expected['commission_amount']);
        expect($commission['payable_date'])->toBe($expected['payable_date']);
        expect($commission['status'])->toBe($expected['status']);
        expect($commission['payout'])->toBe($expected['payout']);
    }

    $paidCommission = collect($portalCommissions)->firstWhere('reference', $paid->reference);
    expect($paidCommission['payout'])->toBe([
        'paid_on' => '2027-12-21',
        'reference' => $paid->reference,
    ]);

    $openCommission = collect($portalCommissions)->firstWhere('reference', $open->reference);
    expect($openCommission['payout'])->toBeNull();
});
