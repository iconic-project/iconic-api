<?php

declare(strict_types=1);

use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\HotelSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(ConfigSeeder::class);
    $this->seed(DemoUsersSeeder::class);
    $this->seed(HotelSeeder::class);
});

test('portal availability matches the engine stay search with net prices', function (): void {
    $agency = approvedAgency(['commission_pct' => 10]);
    $user = agencyUser([], $agency);
    $query = 'check_in=2026-12-21&check_out=2026-12-25&adults=2&rooms=1';

    $public = $this->getJson('/api/engine/availability?'.$query)
        ->assertOk()
        ->json();
    $portal = $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/availability?'.$query)
        ->assertOk()
        ->json();

    $publicFamily = collect($public['room_types'])->firstWhere('code', 'FAM');
    $portalFamily = collect($portal['room_types'])->firstWhere('code', 'FAM');

    expect($publicFamily['bookable'])->toBeTrue()
        ->and($portalFamily['bookable'])->toBeTrue()
        ->and($portalFamily['reasons'])->toBe($publicFamily['reasons'])
        ->and($portalFamily['rooms_left'])->toBe($publicFamily['rooms_left'])
        ->and($portal['commission_pct'])->toBe(10)
        ->and($portalFamily['quotes'][0]['total'])->toBe($agency->netOf($publicFamily['quotes'][0]['total']))
        ->and($portalFamily['quotes'][0]['total'])->not->toBe($publicFamily['quotes'][0]['total']);

    $encoded = json_encode($portal);
    expect($encoded)->not->toContain('"total":'.$publicFamily['quotes'][0]['total']);
});

test('the portal calendar nets the public from price', function (): void {
    $agency = approvedAgency(['commission_pct' => 10]);
    $user = agencyUser([], $agency);

    $public = $this->getJson('/api/engine/calendar?from=2026-12&months=1&adults=2')
        ->assertOk()
        ->json();
    $portal = $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/calendar?from=2026-12&months=1&adults=2')
        ->assertOk()
        ->json();

    $publicNight = collect($public['nights'])->firstWhere('night', '2026-12-21');
    $portalNight = collect($portal['nights'])->firstWhere('night', '2026-12-21');

    expect($publicNight['from_price'])->toBeInt()
        ->and($portalNight['available'])->toBe($publicNight['available'])
        ->and($portalNight['from_price'])->toBe($agency->netOf($publicNight['from_price']))
        ->and($portal['commission_pct'])->toBe(10);
});
