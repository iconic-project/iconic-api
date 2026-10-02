<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\MainChannel;
use App\Models\Booking;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Tests\Support\Bookings\ReservationFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('every list endpoint scoped to agency A never shows agency Bs ids', function (): void {
    $agencyA = approvedAgency(['commission_pct' => 10]);
    $agencyB = approvedAgency(['commission_pct' => 12]);
    $userA = agencyUser([], $agencyA);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');

    Booking::factory()->create([
        'reference' => 'ANK-2026-9001',
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S1')?->id,
        'agency_id' => $agencyA->id,
        'commission_pct' => 10,
        'commission_approved' => true,
        'status' => BookingStatus::Confirmed,
        'total' => 10000,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);
    $bookingB = Booking::factory()->create([
        'reference' => 'ANK-2026-9002',
        'departure_id' => $departure->id,
        'cabin_id' => $departure->property->cabins->firstWhere('code', 'S2')?->id,
        'agency_id' => $agencyB->id,
        'commission_pct' => 12,
        'commission_approved' => true,
        'status' => BookingStatus::Confirmed,
        'total' => 50000,
        'main_channel' => MainChannel::B2BTravelAdvisor,
    ]);

    $bookingsResponse = $this->actingAs($userA, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/bookings')
        ->assertOk();

    $commissionsResponse = $this->actingAs($userA, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/commissions')
        ->assertOk();

    $bookingsEncoded = json_encode($bookingsResponse->json());
    $commissionsEncoded = json_encode($commissionsResponse->json());

    expect($bookingsEncoded)->not->toContain($bookingB->reference);
    expect($commissionsEncoded)->not->toContain($bookingB->reference);
    expect($bookingsResponse->json('data'))->toHaveCount(1);
    expect($commissionsResponse->json('data'))->toHaveCount(1);
});

test('an agency session is never met with a 403 anywhere in the portal', function (): void {
    $agency = approvedAgency();
    $user = agencyUser([], $agency);

    foreach (['/api/portal/me', '/api/portal/rates', '/api/portal/availability', '/api/portal/bookings', '/api/portal/commissions', '/api/portal/sales-materials'] as $uri) {
        $this->actingAs($user, 'agency')
            ->withHeaders(portalHeaders())
            ->getJson($uri)
            ->assertOk();
    }
});
