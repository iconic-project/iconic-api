<?php

declare(strict_types=1);

use App\Enums\DepartureStatus;
use App\Enums\ItineraryStatus;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use App\Models\RateVersion;
use App\Services\Config\CurrentConfig;
use App\Support\BusinessRules\Registry;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

test('publishing rates without 2027 is refused while demo departures exist', function (): void {
    $this->seed(DemoInventorySeeder::class);
    $admin = adminUser();
    $document = ratesDocument();
    $document['years'] = array_values(array_filter(
        $document['years'],
        fn (array $year): bool => $year['year'] !== 2027,
    ));

    $this->actingAs($admin)
        ->postJson('/api/rms/rates/validate', ['document' => $document])
        ->assertOk()
        ->assertJsonPath('errors.years.0', "Can't remove 2027 — 16 departures sail that year.");

    $publish = $this->actingAs($admin)
        ->postJson('/api/rms/rates/versions', [
            'document' => $document,
            'base_version' => 1,
            'approval_reference' => 'BOARD-NO-2027',
        ])
        ->assertUnprocessable();

    expect($publish->json('errors')['document.years'][0] ?? null)
        ->toBe("Can't remove 2027 — 16 departures sail that year.");

    expect(RateVersion::query()->count())->toBe(1);
});

test('removing a year with no departures is allowed', function (): void {
    $this->seed(DemoInventorySeeder::class);
    $document = ratesDocument();
    $document['years'] = array_values(array_filter(
        $document['years'],
        fn (array $year): bool => $year['year'] !== 2028,
    ));

    $this->actingAs(adminUser())
        ->postJson('/api/rms/rates/validate', ['document' => $document])
        ->assertOk()
        ->assertJsonPath('errors', []);
});

test('departures in a year with no rates warn', function (): void {
    $itinerary = Itinerary::factory()->create(['status' => ItineraryStatus::Published]);
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2031-01-05',
        'status' => DepartureStatus::OnSale,
    ]);

    $response = $this->actingAs(adminUser())
        ->postJson('/api/rms/rates/validate', ['document' => ratesDocument()])
        ->assertOk();

    $yearWarning = collect($response->json('warnings'))->firstWhere('path', 'years');

    expect($yearWarning['message'] ?? null)->toBe('Departures in 2031 have no rates.');
});

test('engine settings warn when the default search starts before the first bookable month', function (): void {
    $this->seed(DemoInventorySeeder::class);
    $document = engineSettingsDocument();
    $document['calendar']['default_search_from'] = '2027-10';

    $tooEarly = $this->actingAs(adminUser())
        ->postJson('/api/rms/engine-settings/validate', ['document' => $document])
        ->assertOk();

    $searchWarning = collect($tooEarly->json('warnings'))
        ->firstWhere('path', 'calendar.default_search_from');

    expect($searchWarning['message'])->toBe(
        'Default search starts before the first bookable month (Nov 2027) — guests would open on empty months.',
    );

    $document['calendar']['default_search_from'] = '2027-11';

    $ok = $this->actingAs(adminUser())
        ->postJson('/api/rms/engine-settings/validate', ['document' => $document])
        ->assertOk();

    $searchWarnings = collect($ok->json('warnings'))
        ->where('path', 'calendar.default_search_from')
        ->all();

    expect($searchWarnings)->toBeEmpty();
});

test('OPS-006 shows the first cruise and differs only when the date is not 7 Nov 2027', function (): void {
    expect(collect(Registry::rows(app(CurrentConfig::class)))->firstWhere('key', 'ops-006-sales-open'))
        ->toMatchArray([
            'current_display' => 'No departures yet',
            'differs' => null,
            'source_display' => 'Sales open 1 Nov 2026 · first cruise 7 Nov 2027',
        ])
        ->and(collect(Registry::rows(app(CurrentConfig::class)))->firstWhere('key', 'ops-006-sales-open')['note'])
        ->toContain('PRO-001');

    $this->seed(DemoInventorySeeder::class);

    expect(collect(Registry::rows(app(CurrentConfig::class)))->firstWhere('key', 'ops-006-sales-open'))
        ->toMatchArray([
            'current_display' => 'First cruise 7 Nov 2027',
            'differs' => false,
        ]);

    $itinerary = Itinerary::factory()->create(['status' => ItineraryStatus::Published]);
    $property = Property::query()->where('code', 'ANATIVA')->firstOrFail();
    Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2027-10-31',
        'status' => DepartureStatus::OnSale,
    ]);

    expect(collect(Registry::rows(app(CurrentConfig::class)))->firstWhere('key', 'ops-006-sales-open'))
        ->toMatchArray([
            'current_display' => 'First cruise 31 Oct 2027',
            'differs' => true,
        ]);
});
