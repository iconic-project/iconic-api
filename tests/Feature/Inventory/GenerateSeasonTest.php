<?php

declare(strict_types=1);

use App\Enums\ItineraryStatus;
use App\Models\ChangeHistory;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);

    foreach (['WEST' => false, 'NORTH' => false, 'FEST' => true] as $code => $festive) {
        Itinerary::factory()->create([
            'code' => $code,
            'festive' => $festive,
            'status' => ItineraryStatus::Published,
        ]);
    }
});

/**
 * @return array{from: string, to: string, property_ids: list<int>, pattern: string, festive_window: bool, status: string}
 */
function seasonPayload(array $overrides = []): array
{
    return [
        'from' => '2028-01-02',
        'to' => '2028-02-06',
        'property_ids' => [
            Property::query()->where('code', 'ANAMARA')->value('id'),
            Property::query()->where('code', 'ANATIVA')->value('id'),
        ],
        'pattern' => 'ALT',
        'festive_window' => false,
        'status' => 'CLOSED',
        ...$overrides,
    ];
}

/**
 * Hand-written from runSeason `(w + yi) % 2` with ANAMARA then ANATIVA.
 *
 * @return list<array{date: string, ANAMARA: string, ANATIVA: string}>
 */
function altSixWeekTable(): array
{
    return [
        ['date' => '2028-01-02', 'ANAMARA' => 'WEST', 'ANATIVA' => 'NORTH'],
        ['date' => '2028-01-09', 'ANAMARA' => 'NORTH', 'ANATIVA' => 'WEST'],
        ['date' => '2028-01-16', 'ANAMARA' => 'WEST', 'ANATIVA' => 'NORTH'],
        ['date' => '2028-01-23', 'ANAMARA' => 'NORTH', 'ANATIVA' => 'WEST'],
        ['date' => '2028-01-30', 'ANAMARA' => 'WEST', 'ANATIVA' => 'NORTH'],
        ['date' => '2028-02-06', 'ANAMARA' => 'NORTH', 'ANATIVA' => 'WEST'],
    ];
}

test('generate season alternates west and north for two properties over six weeks', function (): void {
    $mateo = managerUser();

    $response = $this->actingAs($mateo)
        ->postJson('/api/rms/departures/generate-season', seasonPayload())
        ->assertOk();

    expect($response->json('created'))->toHaveCount(12);
    expect($response->json('skipped'))->toBe([]);
    expect($response->json())->toHaveKeys(['created', 'skipped']);
    expect($response->json())->not->toHaveKey('data');

    foreach (altSixWeekTable() as $week) {
        foreach (['ANAMARA', 'ANATIVA'] as $property) {
            $departure = Departure::query()
                ->whereHas('property', fn ($query) => $query->where('code', $property))
                ->whereDate('date', $week['date'])
                ->with('itinerary')
                ->firstOrFail();

            expect($departure->itinerary->code)->toBe($week[$property]);
            expect($departure->festive)->toBeFalse();
            expect($departure->status->value)->toBe('CLOSED');
        }
    }
});

test('generate season 2 jan to 26 mar 2028 creates 26 opposite-route departures', function (): void {
    $mateo = managerUser();

    $response = $this->actingAs($mateo)
        ->postJson('/api/rms/departures/generate-season', seasonPayload([
            'from' => '2028-01-02',
            'to' => '2028-03-26',
        ]))
        ->assertOk();

    expect($response->json('created'))->toHaveCount(26);

    $byDate = Departure::query()
        ->with(['property', 'itinerary'])
        ->orderBy('date')
        ->get()
        ->groupBy(fn (Departure $departure): string => $departure->date->toDateString());

    expect($byDate)->toHaveCount(13);

    foreach ($byDate as $pair) {
        expect($pair)->toHaveCount(2);
        expect($pair[0]->itinerary->code)->not->toBe($pair[1]->itinerary->code);
        expect($pair->pluck('property.code')->sort()->values()->all())->toBe(['ANAMARA', 'ANATIVA']);
    }
});

test('the festive window marks 2 jan as fest when enabled', function (): void {
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->postJson('/api/rms/departures/generate-season', seasonPayload([
            'from' => '2028-01-02',
            'to' => '2028-01-09',
            'festive_window' => true,
        ]))
        ->assertOk();

    $jan2 = Departure::query()->whereDate('date', '2028-01-02')->with('itinerary')->get();
    $jan9 = Departure::query()->whereDate('date', '2028-01-09')->with('itinerary')->get();

    expect($jan2)->toHaveCount(2);
    expect($jan2->every(fn (Departure $departure): bool => $departure->itinerary->code === 'FEST' && $departure->festive))->toBeTrue();
    expect($jan9->every(fn (Departure $departure): bool => $departure->itinerary->code !== 'FEST' && ! $departure->festive))->toBeTrue();
});

test('a festive window crossing 15 dec and 2 jan uses fest only inside the window', function (): void {
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->postJson('/api/rms/departures/generate-season', seasonPayload([
            'from' => '2027-12-12',
            'to' => '2028-01-09',
            'festive_window' => true,
        ]))
        ->assertOk();

    $expected = [
        '2027-12-12' => false,
        '2027-12-19' => true,
        '2027-12-26' => true,
        '2028-01-02' => true,
        '2028-01-09' => false,
    ];

    foreach ($expected as $date => $festive) {
        $rows = Departure::query()->whereDate('date', $date)->with('itinerary')->get();
        expect($rows)->toHaveCount(2);
        expect($rows->every(function (Departure $departure) use ($festive): bool {
            return $departure->festive === $festive
                && $departure->itinerary->code === ($festive ? 'FEST' : $departure->itinerary->code);
        }))->toBeTrue();

        if ($festive) {
            expect($rows->every(fn (Departure $departure): bool => $departure->itinerary->code === 'FEST'))->toBeTrue();
        }
    }
});

test('existing property-date pairs are skipped', function (): void {
    $west = Itinerary::query()->where('code', 'WEST')->firstOrFail();
    Departure::factory()->create([
        'property_id' => Property::query()->where('code', 'ANAMARA')->value('id'),
        'itinerary_id' => $west->id,
        'date' => '2028-01-02',
    ]);
    $mateo = managerUser();

    $response = $this->actingAs($mateo)
        ->postJson('/api/rms/departures/generate-season', seasonPayload([
            'to' => '2028-01-02',
        ]))
        ->assertOk();

    expect($response->json('created'))->toHaveCount(1);
    expect($response->json('skipped'))->toBe([
        ['property' => 'ANAMARA', 'date' => '2028-01-02'],
    ]);
});

test('a missing itinerary code returns 422', function (): void {
    Itinerary::query()->where('code', 'FEST')->delete();
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->postJson('/api/rms/departures/generate-season', seasonPayload([
            'festive_window' => true,
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.pattern.0', 'Itinerary code FEST does not exist.');
});

test('a range longer than 18 months returns 422', function (): void {
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->postJson('/api/rms/departures/generate-season', seasonPayload([
            'from' => '2028-01-02',
            'to' => '2029-07-03',
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.to.0', 'The range cannot be longer than 18 months.');
});

test('each generated departure writes departure.created with generate-season context', function (): void {
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->postJson('/api/rms/departures/generate-season', seasonPayload([
            'to' => '2028-01-02',
        ]))
        ->assertOk();

    $entries = ChangeHistory::query()->where('event', 'departure.created')->get();

    expect($entries)->toHaveCount(2);
    expect($entries->every(fn (ChangeHistory $entry): bool => ($entry->context['action'] ?? null) === 'generate season'))->toBeTrue();
});
