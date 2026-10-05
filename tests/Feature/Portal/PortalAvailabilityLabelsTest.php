<?php

declare(strict_types=1);

use App\Enums\ClaimKind;
use App\Services\Inventory\ClaimService;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('an available departure shows the same label the public engine feed shows', function (): void {
    $agency = approvedAgency();
    $user = agencyUser([], $agency);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-07');

    $publicRows = $this->getJson('/api/engine/feed')
        ->assertOk()
        ->json('departures');
    $publicRow = collect($publicRows)->firstWhere('id', $departure->id);

    $portalRow = collect(
        $this->actingAs($user, 'agency')
            ->withHeaders(portalHeaders())
            ->getJson('/api/portal/availability')
            ->assertOk()
            ->json('data'),
    )->firstWhere('id', $departure->id);

    expect($publicRow['label'])->toBe('AVAILABLE');
    expect($portalRow['label']['text'])->toBe($publicRow['label']);
});

test('a fully sold departure shows the same label the public engine feed shows', function (): void {
    $agency = approvedAgency();
    $user = agencyUser([], $agency);
    $departure = ReservationFixtures::anamaraDeparture('2027-11-14');

    $holderA = ClaimHolder::query()->create(['reference' => 'LBL-A', 'name' => 'A']);
    $holderB = ClaimHolder::query()->create(['reference' => 'LBL-B', 'name' => 'B']);
    $suites = $departure->property->cabins()->where('code', '!=', 'OWNER')->get();
    $owner = $departure->property->cabins()->where('code', 'OWNER')->get();

    DB::transaction(function () use ($departure, $holderA, $holderB, $suites, $owner): void {
        $service = app(ClaimService::class);
        $service->claim($departure->stayDates(), $suites, $holderA, ClaimKind::Booking);
        $service->claim($departure->stayDates(), $owner, $holderB, ClaimKind::Booking);
    });

    $publicRows = $this->getJson('/api/engine/feed')
        ->assertOk()
        ->json('departures');
    $publicRow = collect($publicRows)->firstWhere('id', $departure->id);

    $portalRow = collect(
        $this->actingAs($user, 'agency')
            ->withHeaders(portalHeaders())
            ->getJson('/api/portal/availability')
            ->assertOk()
            ->json('data'),
    )->firstWhere('id', $departure->id);

    expect($publicRow['label'])->toStartWith('FULL');
    expect($portalRow['label']['text'])->toBe($publicRow['label']);
});
