<?php

declare(strict_types=1);

use App\Enums\AgencyStatus;
use App\Services\Config\CurrentConfig;
use App\Support\Portal\PortalStayRates;
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

test('me shows the agency commercial profile and the user, with materials not yet available', function (): void {
    $agency = approvedAgency([
        'name' => 'Blue Latitude',
        'commission_pct' => 10,
        'payment_terms' => '30 days post-cruise · wire',
    ]);
    $user = agencyUser(['name' => 'Ana Agent', 'email' => 'ana@agency.test'], $agency);

    $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/me')
        ->assertOk()
        ->assertJsonPath('agency.name', 'Blue Latitude')
        ->assertJsonPath('agency.reference', $agency->reference)
        ->assertJsonPath('agency.commission_pct', 10)
        ->assertJsonPath('agency.payment_terms', '30 days post-cruise · wire')
        ->assertJsonPath('agency.status', AgencyStatus::Approved->value)
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.name', 'Ana Agent')
        ->assertJsonPath('user.email', 'ana@agency.test')
        ->assertJsonPath('materials_exist', false);
});

test('rates returns the published stay matrix net of commission', function (): void {
    $agency = approvedAgency(['commission_pct' => 10]);
    $user = agencyUser([], $agency);
    $config = app(CurrentConfig::class);
    $rates = $config->rates();

    $response = $this->actingAs($user, 'agency')
        ->withHeaders(portalHeaders())
        ->getJson('/api/portal/rates')
        ->assertOk();

    expect($response->json())->toBe(PortalStayRates::document($agency, $rates, $config));

    $family = collect($response->json('room_rates'))->first(
        fn (array $row): bool => $row['room_type'] === 'FAM' && $row['season'] === 'LOW',
    );
    $public = collect($rates->roomRates)->first(
        fn ($row): bool => $row->roomType === 'FAM' && $row->season === 'LOW',
    );

    expect($family['nightly'])->toBe($agency->netOf($public->nightly))
        ->and($response->json('seasons'))->not->toBeEmpty()
        ->and($response->json('rate_plans'))->not->toBeEmpty();

    $encoded = json_encode($response->json());
    expect($encoded)->not->toContain('"nightly":'.$public->nightly);
});
