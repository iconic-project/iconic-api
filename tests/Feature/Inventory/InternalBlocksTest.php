<?php

declare(strict_types=1);

use App\Enums\BlockReason;
use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ItineraryStatus;
use App\Models\CabinClaim;
use App\Models\ChangeHistory;
use App\Models\Departure;
use App\Models\InternalBlock;
use App\Models\Itinerary;
use App\Models\Property;
use App\Services\Inventory\ClaimService;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

/**
 * @return array{0: Departure, 1: Departure}
 */
function twoBlockDepartures(): array
{
    $anamara = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $anativa = Property::query()->where('code', 'ANATIVA')->firstOrFail();
    $itinerary = Itinerary::factory()->create(['status' => ItineraryStatus::Published]);

    $first = Departure::factory()->create([
        'property_id' => $anamara->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-02',
        'reference' => 'DEP-101',
    ]);
    $second = Departure::factory()->create([
        'property_id' => $anativa->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-09',
        'reference' => 'DEP-102',
    ]);

    return [$first, $second];
}

test('creating a block across two departures is all-or-nothing and lists every collision', function (): void {
    [$first, $second] = twoBlockDepartures();
    $holder = ClaimHolder::query()->create(['reference' => 'HLD-1', 'name' => 'Hold']);
    $s1 = $first->property->cabins()->where('code', 'S1')->firstOrFail();
    $s2 = $second->property->cabins()->where('code', 'S2')->firstOrFail();

    DB::transaction(function () use ($first, $second, $holder, $s1, $s2): void {
        app(ClaimService::class)->claim($first, collect([$s1]), $holder, ClaimKind::Hold, HoldType::Agency, now()->addDay());
        app(ClaimService::class)->claim($second, collect([$s2]), $holder, ClaimKind::Hold, HoldType::Agency, now()->addDay());
    });

    $response = $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Maintenance->value,
        'departures' => [
            ['departure_id' => $first->id, 'cabin_codes' => ['S1', 'S2', 'S3']],
            ['departure_id' => $second->id, 'cabin_codes' => ['S1', 'S2', 'S3']],
        ],
    ]);

    $response->assertConflict();
    expect($response->json('message'))->toContain('Suite 01 on 2 Apr 2028 · ANAMARA is held.');
    expect($response->json('message'))->toContain('Suite 02 on 9 Apr 2028 · ANATIVA is held.');
    expect($response->json('unavailable'))->toHaveCount(2);
    expect(InternalBlock::query()->count())->toBe(0);
    expect(CabinClaim::query()->where('kind', ClaimKind::Block)->count())->toBe(0);
});

test('ALL claims every cabin on the property', function (): void {
    [$first] = twoBlockDepartures();

    $this->actingAs(managerUser())
        ->postJson('/api/rms/blocks', [
            'reason' => BlockReason::Courtesy->value,
            'departures' => [
                ['departure_id' => $first->id, 'cabin_codes' => 'ALL'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('scope_summary', 'ANAMARA · Full property · 2 Apr 2028');

    $block = InternalBlock::query()->firstOrFail();
    expect($block->claims()->whereNull('released_at')->count())->toBe(9);
});

test('release frees the cabins and a second release is 409', function (): void {
    [$first] = twoBlockDepartures();
    $mateo = managerUser();

    $created = $this->actingAs($mateo)->postJson('/api/rms/blocks', [
        'reason' => BlockReason::FamTrip->value,
        'notes' => 'Agents',
        'departures' => [
            ['departure_id' => $first->id, 'cabin_codes' => ['S1', 'S2', 'S3']],
        ],
    ])->assertCreated();

    $id = $created->json('id');

    $this->actingAs($mateo)
        ->postJson("/api/rms/blocks/{$id}/release", ['note' => 'Done'])
        ->assertOk()
        ->assertJsonPath('release_note', 'Done');

    expect(CabinClaim::query()->where('holder_id', $id)->whereNull('released_at')->count())->toBe(0);

    $this->actingAs($mateo)
        ->getJson('/api/rms/blocks?status=released')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->actingAs($mateo)
        ->getJson('/api/rms/blocks')
        ->assertOk()
        ->assertJsonPath('data', []);

    $this->actingAs($mateo)
        ->postJson("/api/rms/blocks/{$id}/release")
        ->assertConflict()
        ->assertJsonPath('message', 'This block is already released.');
});

test('notes and reason can change and the scope does not', function (): void {
    [$first] = twoBlockDepartures();
    $mateo = managerUser();

    $created = $this->actingAs($mateo)->postJson('/api/rms/blocks', [
        'reason' => BlockReason::FamTrip->value,
        'notes' => 'Before',
        'departures' => [
            ['departure_id' => $first->id, 'cabin_codes' => ['S7', 'S8']],
        ],
    ])->assertCreated();

    $id = $created->json('id');

    $this->actingAs($mateo)
        ->patchJson("/api/rms/blocks/{$id}", [
            'reason' => BlockReason::Courtesy->value,
            'notes' => 'After',
        ])
        ->assertOk()
        ->assertJsonPath('reason', BlockReason::Courtesy->value)
        ->assertJsonPath('notes', 'After')
        ->assertJsonPath('scope_summary', 'ANAMARA · Suite 07–08 · 2 Apr 2028');

    expect(CabinClaim::query()->where('holder_id', $id)->whereNull('released_at')->count())->toBe(2);
    expect(ChangeHistory::query()->where('event', 'block.updated')->count())->toBe(1);
});

test('lucia can read blocks and cannot write', function (): void {
    [$first] = twoBlockDepartures();
    $lucia = salesExecUser();
    $mateo = managerUser();

    $created = $this->actingAs($mateo)->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Maintenance->value,
        'departures' => [
            ['departure_id' => $first->id, 'cabin_codes' => ['S1']],
        ],
    ])->assertCreated();

    $id = $created->json('id');

    $this->actingAs($lucia)->getJson('/api/rms/blocks')->assertOk();
    $this->actingAs($lucia)->getJson("/api/rms/blocks/{$id}/history")->assertOk();
    $this->actingAs($lucia)->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Courtesy->value,
        'departures' => [
            ['departure_id' => $first->id, 'cabin_codes' => ['S2']],
        ],
    ])->assertForbidden();
    $this->actingAs($lucia)->patchJson("/api/rms/blocks/{$id}", ['notes' => 'no'])->assertForbidden();
    $this->actingAs($lucia)->postJson("/api/rms/blocks/{$id}/release")->assertForbidden();
});

test('blocked cabins show as BLOCKED on the calendar and become free after release', function (): void {
    [$first, $second] = twoBlockDepartures();
    $mateo = managerUser();

    $created = $this->actingAs($mateo)->postJson('/api/rms/blocks', [
        'reason' => BlockReason::NegotiationHold->value,
        'departures' => [
            ['departure_id' => $first->id, 'cabin_codes' => ['S1', 'S2', 'S3']],
            ['departure_id' => $second->id, 'cabin_codes' => ['S1', 'S2', 'S3']],
        ],
    ])->assertCreated();

    $calendar = $this->actingAs($mateo)
        ->getJson('/api/rms/calendar?from=2028-04-01&to=2028-04-30')
        ->assertOk();

    $blocked = 0;

    foreach ($calendar->json('rows') as $row) {
        foreach ($row['cells'] as $cell) {
            if ($cell['state'] === 'BLOCKED') {
                $blocked++;
            }
        }
    }

    expect($blocked)->toBe(6);

    $this->actingAs($mateo)
        ->getJson("/api/rms/departures/{$first->id}/layout")
        ->assertOk()
        ->assertJsonPath('availability.counts.blocked', 3);

    $this->actingAs($mateo)
        ->postJson('/api/rms/blocks/'.$created->json('id').'/release')
        ->assertOk();

    $after = $this->actingAs($mateo)
        ->getJson('/api/rms/calendar?from=2028-04-01&to=2028-04-30')
        ->assertOk();

    foreach ($after->json('rows') as $row) {
        foreach ($row['cells'] as $cell) {
            expect($cell['state'])->toBe('FREE');
        }
    }

    $this->actingAs($mateo)
        ->getJson('/api/rms/blocks?status=released')
        ->assertOk()
        ->assertJsonPath('data.0.id', $created->json('id'));
});

test('a departure with an active block cannot be deleted but can change date', function (): void {
    [$first] = twoBlockDepartures();
    $mateo = managerUser();

    $this->actingAs($mateo)->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Maintenance->value,
        'departures' => [
            ['departure_id' => $first->id, 'cabin_codes' => ['S1', 'S2']],
        ],
    ])->assertCreated();

    $this->actingAs($mateo)
        ->deleteJson("/api/rms/departures/{$first->id}")
        ->assertConflict()
        ->assertJsonPath('message', '2 blocked');

    $this->actingAs($mateo)
        ->patchJson("/api/rms/departures/{$first->id}", [
            'date' => '2028-04-16',
        ])
        ->assertOk()
        ->assertJsonPath('date', '2028-04-16');
});

test('blocks cannot be deleted', function (): void {
    [$first] = twoBlockDepartures();

    $this->actingAs(managerUser())->postJson('/api/rms/blocks', [
        'reason' => BlockReason::Courtesy->value,
        'departures' => [
            ['departure_id' => $first->id, 'cabin_codes' => ['S1']],
        ],
    ])->assertCreated();

    $block = InternalBlock::query()->firstOrFail();

    expect(fn () => $block->delete())->toThrow(LogicException::class, 'Internal blocks cannot be deleted.');
});
