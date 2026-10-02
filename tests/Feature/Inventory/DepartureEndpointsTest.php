<?php

declare(strict_types=1);

use App\Actions\Departures\CreateDeparture;
use App\Actions\Departures\UpdateDeparture;
use App\Enums\DepartureStatus;
use App\Models\ChangeHistory;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use App\Support\Departures\PropertyDateConflict;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function anamara(): Property
{
    return Property::query()->where('code', 'ANAMARA')->firstOrFail();
}

function anativa(): Property
{
    return Property::query()->where('code', 'ANATIVA')->firstOrFail();
}

/**
 * @return array<string, mixed>
 */
function departurePayload(array $overrides = []): array
{
    if (! array_key_exists('itinerary_id', $overrides)) {
        $overrides['itinerary_id'] = Itinerary::factory()->create(['festive' => false])->id;
    }

    return [
        'date' => '2028-04-02',
        'property_id' => anamara()->id,
        'status' => DepartureStatus::OnSale->value,
        'urgency_threshold' => 3,
        'waitlist_enabled' => true,
        'public_note' => 'Inaugural sailing',
        'festive' => false,
        ...$overrides,
    ];
}

function insertDepartureRow(Property $property, Itinerary $itinerary, string $date, string $reference = 'DEP-900'): void
{
    DB::table('departures')->insert([
        'reference' => $reference,
        'date' => $date,
        'property_id' => $property->id,
        'itinerary_id' => $itinerary->id,
        'status' => DepartureStatus::OnSale->value,
        'urgency_threshold' => 3,
        'waitlist_enabled' => true,
        'public_note' => null,
        'festive' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('lucia can view departures and cannot write', function (): void {
    $itinerary = Itinerary::factory()->create();
    $departure = Departure::factory()->create([
        'property_id' => anamara()->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-02',
    ]);
    $lucia = salesExecUser();

    $this->actingAs($lucia)
        ->getJson('/api/rms/departures')
        ->assertOk()
        ->assertJsonPath('data.0.reference', $departure->reference);

    $this->actingAs($lucia)
        ->getJson("/api/rms/departures/{$departure->id}")
        ->assertOk();

    $this->actingAs($lucia)
        ->getJson("/api/rms/departures/{$departure->id}/history")
        ->assertOk();

    $this->actingAs($lucia)
        ->postJson('/api/rms/departures', departurePayload(['itinerary_id' => $itinerary->id, 'date' => '2028-04-09']))
        ->assertForbidden();

    $this->actingAs($lucia)
        ->patchJson("/api/rms/departures/{$departure->id}", ['status' => 'CLOSED'])
        ->assertForbidden();

    $this->actingAs($lucia)
        ->deleteJson("/api/rms/departures/{$departure->id}")
        ->assertForbidden();

    $this->actingAs($lucia)
        ->postJson('/api/rms/departures/generate-season', [
            'from' => '2028-01-02',
            'to' => '2028-01-09',
            'property_ids' => [anamara()->id],
            'pattern' => 'WEST',
            'festive_window' => false,
            'status' => 'CLOSED',
        ])
        ->assertForbidden();
});

test('mateo can create a departure with return_date and empty warnings', function (): void {
    $mateo = managerUser();
    $payload = departurePayload();

    $response = $this->actingAs($mateo)
        ->postJson('/api/rms/departures', $payload);

    $response->assertCreated()
        ->assertJsonPath('date', '2028-04-02')
        ->assertJsonPath('return_date', '2028-04-09')
        ->assertJsonPath('status', 'ON_SALE')
        ->assertJsonPath('public_note', 'Inaugural sailing')
        ->assertJsonPath('property.code', 'ANAMARA')
        ->assertJsonPath('itinerary.id', $payload['itinerary_id'])
        ->assertJsonPath('rates.year', 2028)
        ->assertJsonPath('rates.suite_from', 13965)
        ->assertJsonPath('warnings', []);

    $created = $response->json();
    expect($created)->toHaveKeys(['id', 'reference', 'warnings', 'availability', 'locks']);
    expect($created)->not->toHaveKey('data');

    expect(ChangeHistory::query()->where('event', 'departure.created')->count())->toBe(1);
    expect(ChangeHistory::query()->where('event', 'departure.created')->value('subject_type'))->toBe('departure');
});

test('a monday is refused with the prototype sunday message', function (): void {
    $mateo = managerUser();
    $monday = CarbonImmutable::createFromFormat('!Y-m-d', '2028-04-03');

    $this->actingAs($mateo)
        ->postJson('/api/rms/departures', departurePayload(['date' => '2028-04-03']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.date.0', PropertyDateConflict::sundayMessage($monday));
});

test('a duplicate property and date is refused with the prototype uniqueness message', function (): void {
    $itinerary = Itinerary::factory()->create();
    $existing = Departure::factory()->create([
        'property_id' => anamara()->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-02',
        'reference' => 'DEP-044',
    ]);
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->postJson('/api/rms/departures', departurePayload([
            'itinerary_id' => $itinerary->id,
            'date' => '2028-04-02',
        ]))
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.date.0',
            PropertyDateConflict::duplicateMessage('ANAMARA', $existing->date, 'DEP-044'),
        );
});

test('create catches a unique property-date violation inserted before the action', function (): void {
    $itinerary = Itinerary::factory()->create();
    insertDepartureRow(anamara(), $itinerary, '2028-04-02', 'DEP-900');
    $mateo = managerUser();
    $this->actingAs($mateo);

    try {
        app(CreateDeparture::class)->handle([
            'property_id' => anamara()->id,
            'date' => '2028-04-02',
            'itinerary_id' => $itinerary->id,
            'status' => DepartureStatus::OnSale,
        ]);
        $this->fail('Expected a unique-violation 422.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['date'][0] ?? null)->toBe(
            PropertyDateConflict::duplicateMessage(
                'ANAMARA',
                CarbonImmutable::createFromFormat('!Y-m-d', '2028-04-02'),
                'DEP-900',
            ),
        );
    }
});

test('update catches a unique property-date violation inserted before the action', function (): void {
    $itinerary = Itinerary::factory()->create();
    $departure = Departure::factory()->create([
        'property_id' => anamara()->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-09',
    ]);
    insertDepartureRow(anamara(), $itinerary, '2028-04-02', 'DEP-901');
    $mateo = managerUser();
    $this->actingAs($mateo);

    try {
        app(UpdateDeparture::class)->handle($departure, ['date' => '2028-04-02']);
        $this->fail('Expected a unique-violation 422.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['date'][0] ?? null)->toBe(
            PropertyDateConflict::duplicateMessage(
                'ANAMARA',
                CarbonImmutable::createFromFormat('!Y-m-d', '2028-04-02'),
                'DEP-901',
            ),
        );
    }
});

test('a festive twin warning is returned without blocking create', function (): void {
    $west = Itinerary::factory()->create(['code' => 'WEST', 'festive' => false]);
    Departure::factory()->create([
        'property_id' => anativa()->id,
        'itinerary_id' => $west->id,
        'date' => '2027-12-19',
        'festive' => false,
    ]);
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->postJson('/api/rms/departures', departurePayload([
            'itinerary_id' => $west->id,
            'date' => '2027-12-19',
            'festive' => true,
        ]))
        ->assertCreated()
        ->assertJsonPath('warnings.0', "ANATIVA's departure on 19 Dec 2027 is not festive.")
        ->assertJsonPath('warnings.1', 'This departure is festive but itinerary WEST is not.');
});

test('updating a non-festive departure warns when the twin is festive', function (): void {
    $west = Itinerary::factory()->create(['code' => 'WEST', 'festive' => false]);
    $fest = Itinerary::factory()->create(['code' => 'FEST', 'festive' => true]);
    Departure::factory()->create([
        'property_id' => anativa()->id,
        'itinerary_id' => $fest->id,
        'date' => '2027-12-19',
        'festive' => true,
    ]);
    $departure = Departure::factory()->create([
        'property_id' => anamara()->id,
        'itinerary_id' => $west->id,
        'date' => '2027-12-19',
        'festive' => true,
    ]);
    $mateo = managerUser();

    $updated = $this->actingAs($mateo)
        ->patchJson("/api/rms/departures/{$departure->id}", ['festive' => false])
        ->assertOk()
        ->assertJsonPath('warnings.0', "ANATIVA's departure on 19 Dec 2027 is festive.")
        ->json();

    expect($updated)->toHaveKeys(['id', 'reference', 'warnings', 'availability', 'locks']);
    expect($updated)->not->toHaveKey('data');
});

test('a non-festive departure on a festive itinerary warns', function (): void {
    $fest = Itinerary::factory()->create(['code' => 'FEST', 'festive' => true]);
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->postJson('/api/rms/departures', departurePayload([
            'itinerary_id' => $fest->id,
            'festive' => false,
        ]))
        ->assertCreated()
        ->assertJsonPath('warnings.0', 'This departure is not festive but itinerary FEST is festive.');
});

test('the list filters by date property and status and paginates', function (): void {
    $west = Itinerary::factory()->create(['code' => 'WEST']);
    $north = Itinerary::factory()->create(['code' => 'NORTH']);

    Departure::factory()->create([
        'property_id' => anamara()->id,
        'itinerary_id' => $west->id,
        'date' => '2028-04-02',
        'status' => DepartureStatus::OnSale,
        'reference' => 'DEP-101',
    ]);
    Departure::factory()->create([
        'property_id' => anativa()->id,
        'itinerary_id' => $north->id,
        'date' => '2028-04-02',
        'status' => DepartureStatus::Closed,
        'reference' => 'DEP-102',
    ]);
    Departure::factory()->create([
        'property_id' => anamara()->id,
        'itinerary_id' => $west->id,
        'date' => '2028-04-09',
        'status' => DepartureStatus::OnSale,
        'reference' => 'DEP-103',
    ]);

    $mateo = managerUser();

    $this->actingAs($mateo)
        ->getJson('/api/rms/departures?from=2028-04-02&to=2028-04-02')
        ->assertOk()
        ->assertJsonPath('data.0.property.code', 'ANAMARA')
        ->assertJsonPath('data.1.property.code', 'ANATIVA')
        ->assertJsonCount(2, 'data');

    $this->actingAs($mateo)
        ->getJson('/api/rms/departures?property_id='.anamara()->id)
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($mateo)
        ->getJson('/api/rms/departures?status=CLOSED')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', 'DEP-102');
});

test('a 2031 departure has a null rates hint', function (): void {
    $itinerary = Itinerary::factory()->create();
    $departure = Departure::factory()->create([
        'property_id' => anamara()->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2031-01-05',
    ]);
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->getJson("/api/rms/departures/{$departure->id}")
        ->assertOk()
        ->assertJsonPath('rates.year', 2031)
        ->assertJsonPath('rates.suite_from', null);
});

test('mateo can delete a departure', function (): void {
    $itinerary = Itinerary::factory()->create();
    $departure = Departure::factory()->create([
        'property_id' => anamara()->id,
        'itinerary_id' => $itinerary->id,
    ]);
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->deleteJson("/api/rms/departures/{$departure->id}")
        ->assertNoContent();

    expect(Departure::query()->whereKey($departure->id)->exists())->toBeFalse();
    expect(ChangeHistory::query()->where('event', 'departure.deleted')->count())->toBe(1);
});
