<?php

declare(strict_types=1);

use App\Enums\ClaimKind;
use App\Enums\DepartureStatus;
use App\Enums\HoldType;
use App\Enums\ItineraryStatus;
use App\Models\RoomNightClaim;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use App\Services\Inventory\Availability;
use App\Services\Inventory\ClaimService;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('lucia can read calendar and layout', function (): void {
    $this->seed(DemoInventorySeeder::class);
    $lucia = salesExecUser();
    $departure = Departure::query()->where('reference', 'DEP-001')->firstOrFail();

    $calendar = $this->actingAs($lucia)
        ->getJson('/api/rms/calendar?from=2027-11-01&to=2027-12-31')
        ->assertOk()
        ->assertJsonPath('departures.0.reference', 'DEP-001')
        ->json();

    expect($calendar)->toHaveKeys(['departures', 'rows']);
    expect($calendar)->not->toHaveKey('data');

    $this->actingAs($lucia)
        ->getJson("/api/rms/departures/{$departure->id}/layout")
        ->assertOk()
        ->assertJsonPath('reference', 'DEP-001')
        ->assertJsonPath('availability.cabins.0.state', 'FREE')
        ->assertJsonCount(9, 'availability.cabins');
});

test('the demo calendar is free except the seeded fam-trip block', function (): void {
    $this->seed(DemoInventorySeeder::class);
    $mateo = managerUser();

    $response = $this->actingAs($mateo)
        ->getJson('/api/rms/calendar?from=2027-11-01&to=2027-12-31')
        ->assertOk();

    expect($response->json('departures'))->toHaveCount(16);

    $blocked = [];

    foreach ($response->json('rows') as $row) {
        foreach ($row['cells'] as $departureId => $cell) {
            if ($cell['state'] === 'FREE') {
                expect($cell['claim'])->toBeNull();

                continue;
            }

            $blocked[] = [
                'property' => $row['property']['code'],
                'cabin' => $row['cabin']['code'],
                'state' => $cell['state'],
                'reference' => $cell['claim']['holder']['reference'] ?? null,
                'detail' => $cell['claim']['holder']['detail'] ?? null,
            ];
        }
    }

    expect($blocked)->toHaveCount(2);
    expect(collect($blocked)->pluck('cabin')->sort()->values()->all())->toBe(['S7', 'S8']);
    expect(collect($blocked)->pluck('property')->unique()->all())->toBe(['ANAMARA']);
    expect(collect($blocked)->pluck('state')->unique()->all())->toBe(['BLOCKED']);
    expect(collect($blocked)->pluck('reference')->unique()->all())->toBe(['BLK-001']);
    expect(collect($blocked)->pluck('detail')->unique()->all())->toBe([
        ['reason' => 'FAM_TRIP', 'reason_label' => 'Fam trip'],
    ]);
});

test('the demo layout exposes the fam-trip block detail on ANAMARA S7 and S8', function (): void {
    $this->seed(DemoInventorySeeder::class);
    $departure = Departure::query()->where('reference', 'DEP-003')->firstOrFail();

    $response = $this->actingAs(managerUser())
        ->getJson("/api/rms/departures/{$departure->id}/layout")
        ->assertOk();

    $blocked = collect($response->json('availability.cabins'))
        ->filter(fn (array $row): bool => $row['state'] === 'BLOCKED')
        ->values();

    expect($blocked)->toHaveCount(2);
    expect($blocked->pluck('cabin.code')->sort()->values()->all())->toBe(['S7', 'S8']);

    foreach ($blocked as $row) {
        expect($row['claim']['holder']['reference'])->toBe('BLK-001');
        expect($row['claim']['holder']['detail'])->toBe([
            'reason' => 'FAM_TRIP',
            'reason_label' => 'Fam trip',
        ]);
    }

    $free = collect($response->json('availability.cabins'))
        ->first(fn (array $row): bool => $row['state'] === 'FREE');

    expect($free['claim'])->toBeNull();
});

test('a hold claim has a null holder detail', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $departure = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
        'date' => '2028-04-02',
    ]);
    $holder = ClaimHolder::query()->create(['reference' => 'HLD-NULL', 'name' => 'Hold']);
    $cabin = $property->cabins()->where('code', 'S1')->firstOrFail();

    DB::transaction(function () use ($departure, $cabin, $holder): void {
        app(ClaimService::class)->claim($departure->stayDates(),
            collect([$cabin]),
            $holder,
            ClaimKind::Hold,
            HoldType::Agency,
            now()->addDay(),
        );
    });

    $this->actingAs(managerUser())
        ->getJson("/api/rms/departures/{$departure->id}/layout")
        ->assertOk()
        ->assertJsonPath('availability.cabins.0.state', 'HELD')
        ->assertJsonPath('availability.cabins.0.claim.holder.detail', null);
});

test('calendar and the departure list do not N+1 over sixteen departures', function (): void {
    $this->seed(DemoInventorySeeder::class);
    $mateo = managerUser();
    $this->actingAs($mateo);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson('/api/rms/calendar?from=2027-11-01&to=2027-12-31')->assertOk();
    $calendarQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($calendarQueries)->toBeLessThan(20);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson('/api/rms/departures?from=2027-11-01&to=2027-12-31')->assertOk();
    $listQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($listQueries)->toBeLessThan(20);
});

test('kpis are computed over the filtered set not the page', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $published = Itinerary::factory()->create(['status' => ItineraryStatus::Published]);
    $draft = Itinerary::factory()->create(['status' => ItineraryStatus::Draft]);

    Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $published->id,
        'date' => '2028-04-02',
        'status' => DepartureStatus::OnSale,
        'urgency_threshold' => 3,
        'reference' => 'DEP-201',
    ]);
    Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $published->id,
        'date' => '2028-04-09',
        'status' => DepartureStatus::OnSale,
        'urgency_threshold' => 3,
        'reference' => 'DEP-202',
    ]);
    Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $draft->id,
        'date' => '2028-04-16',
        'status' => DepartureStatus::OnSale,
        'reference' => 'DEP-203',
    ]);

    $full = Departure::query()->where('reference', 'DEP-202')->firstOrFail();
    $holderA = ClaimHolder::query()->create(['reference' => 'KPI-A', 'name' => 'A']);
    $holderB = ClaimHolder::query()->create(['reference' => 'KPI-B', 'name' => 'B']);
    $suites = $full->property->cabins()->where('code', '!=', 'OWNER')->get();
    $owner = $full->property->cabins()->where('code', 'OWNER')->get();

    DB::transaction(function () use ($full, $holderA, $holderB, $suites, $owner): void {
        $service = app(ClaimService::class);
        $service->claim($full->stayDates(), $suites, $holderA, ClaimKind::Booking);
        $service->claim($full->stayDates(), $owner, $holderB, ClaimKind::Booking);
    });

    $this->actingAs(managerUser())
        ->getJson('/api/rms/departures?from=2028-04-01&to=2028-04-30&per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.kpis.on_sale_on_engine', 2)
        ->assertJsonPath('meta.kpis.cabins_bookable', 9)
        ->assertJsonPath('meta.kpis.full', 1)
        ->assertJsonPath('meta.kpis.showing_only_n_left', 0);
});

test('an expired unreleased hold counts as free', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $departure = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
        'date' => '2028-04-02',
    ]);
    $holder = ClaimHolder::query()->create(['reference' => 'EXP-FREE', 'name' => 'Expired']);
    $cabin = $property->cabins()->where('code', 'S1')->firstOrFail();

    DB::transaction(function () use ($departure, $cabin, $holder): void {
        app(ClaimService::class)->claim($departure->stayDates(),
            collect([$cabin]),
            $holder,
            ClaimKind::Hold,
            HoldType::Web,
            now()->addMinutes(20),
        );
    });

    RoomNightClaim::query()->where('holder_id', $holder->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    $snapshot = app(Availability::class)->forDepartures(collect([$departure->fresh()]))[$departure->id];

    expect($snapshot->cabins[0]['state'])->toBe('FREE');
    expect($snapshot->counts['free'])->toBe(9);
    expect($snapshot->engineLabel['code'])->toBe('AVAILABLE');
});

test('itinerary rows include live_departures_count', function (): void {
    $published = Itinerary::factory()->create(['status' => ItineraryStatus::Published, 'code' => 'LIVE']);
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

    Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $published->id,
        'date' => '2028-04-02',
        'status' => DepartureStatus::OnSale,
    ]);
    Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $published->id,
        'date' => '2028-04-09',
        'status' => DepartureStatus::Hidden,
    ]);

    $this->actingAs(managerUser())
        ->getJson('/api/rms/itineraries')
        ->assertOk()
        ->assertJsonFragment([
            'code' => 'LIVE',
            'departures_count' => 2,
            'live_departures_count' => 1,
        ]);
});

test('with_cabins includes cabin rows on the list', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
        'date' => '2028-04-02',
    ]);

    $this->actingAs(managerUser())
        ->getJson('/api/rms/departures?with_cabins=1')
        ->assertOk()
        ->assertJsonCount(9, 'data.0.availability.cabins');
});

test('the calendar range cannot exceed 18 months', function (): void {
    $this->actingAs(managerUser())
        ->getJson('/api/rms/calendar?from=2027-01-01&to=2028-08-01')
        ->assertUnprocessable();
});
