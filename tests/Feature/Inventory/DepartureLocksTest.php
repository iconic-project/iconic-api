<?php

declare(strict_types=1);

use App\Enums\ClaimKind;
use App\Enums\HoldType;
use App\Enums\ItineraryStatus;
use App\Enums\ReleaseReason;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Property;
use App\Services\Inventory\ClaimService;
use App\Support\Inventory\DepartureLocks;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Inventory\ClaimHolder;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
});

function lockDeparture(): Departure
{
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();

    return Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => Itinerary::factory()->create(['status' => ItineraryStatus::Published])->id,
        'date' => '2028-04-02',
    ]);
}

test('date and property lock when a hold or booking is active but not for a block', function (): void {
    $departure = lockDeparture();
    $block = ClaimHolder::query()->create(['reference' => 'BLK-1', 'name' => 'Block']);
    $hold = ClaimHolder::query()->create(['reference' => 'HLD-1', 'name' => 'Hold']);
    $s1 = $departure->property->cabins()->where('code', 'S1')->firstOrFail();
    $s2 = $departure->property->cabins()->where('code', 'S2')->firstOrFail();
    $mateo = managerUser();

    DB::transaction(function () use ($departure, $s1, $block): void {
        app(ClaimService::class)->claim($departure, collect([$s1]), $block, ClaimKind::Block);
    });

    $this->actingAs($mateo)
        ->patchJson("/api/rms/departures/{$departure->id}", ['date' => '2028-04-09'])
        ->assertOk()
        ->assertJsonPath('date', '2028-04-09');

    $departure->refresh();

    DB::transaction(function () use ($departure, $s2, $hold): void {
        app(ClaimService::class)->claim(
            $departure,
            collect([$s2]),
            $hold,
            ClaimKind::Hold,
            HoldType::Agency,
            now()->addDay(),
        );
    });

    $this->actingAs($mateo)
        ->getJson("/api/rms/departures/{$departure->id}")
        ->assertOk()
        ->assertJsonPath('locks.date_and_property', true)
        ->assertJsonPath('locks.delete', true);

    $this->actingAs($mateo)
        ->patchJson("/api/rms/departures/{$departure->id}", ['date' => '2028-04-16'])
        ->assertStatus(409)
        ->assertJsonPath('message', DepartureLocks::dateAndPropertyMessage(1));
});

test('delete is refused while any active claim exists', function (): void {
    $departure = lockDeparture();
    $holder = ClaimHolder::query()->create(['reference' => 'BLK-2', 'name' => 'Block']);
    $s1 = $departure->property->cabins()->where('code', 'S1')->firstOrFail();
    $s2 = $departure->property->cabins()->where('code', 'S2')->firstOrFail();

    DB::transaction(function () use ($departure, $s1, $s2, $holder): void {
        app(ClaimService::class)->claim($departure, collect([$s1, $s2]), $holder, ClaimKind::Block);
    });

    $this->actingAs(managerUser())
        ->deleteJson("/api/rms/departures/{$departure->id}")
        ->assertStatus(409)
        ->assertJsonPath('message', '2 blocked');
});

test('a released claim still blocks delete with the history message', function (): void {
    $departure = lockDeparture();
    $holder = ClaimHolder::query()->create(['reference' => 'BLK-3', 'name' => 'Block']);
    $cabin = $departure->property->cabins()->where('code', 'S7')->firstOrFail();

    DB::transaction(function () use ($departure, $cabin, $holder): void {
        $service = app(ClaimService::class);
        $service->claim($departure, collect([$cabin]), $holder, ClaimKind::Block);
        $service->release($holder, ReleaseReason::Released);
    });

    $this->actingAs(managerUser())
        ->getJson("/api/rms/departures/{$departure->id}")
        ->assertOk()
        ->assertJsonPath('locks.date_and_property', false)
        ->assertJsonPath('locks.delete', true)
        ->assertJsonPath('locks.reason', DepartureLocks::HISTORY_DELETE);

    $this->actingAs(managerUser())
        ->deleteJson("/api/rms/departures/{$departure->id}")
        ->assertStatus(409)
        ->assertJsonPath('message', DepartureLocks::HISTORY_DELETE);

    expect(Departure::query()->whereKey($departure->id)->exists())->toBeTrue();
});
