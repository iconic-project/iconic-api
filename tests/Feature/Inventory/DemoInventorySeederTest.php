<?php

declare(strict_types=1);

use App\Enums\BlockReason;
use App\Enums\ClaimKind;
use App\Enums\DepartureStatus;
use App\Enums\ItineraryStatus;
use App\Enums\ReferenceType;
use App\Models\Departure;
use App\Models\InternalBlock;
use App\Models\Itinerary;
use App\Models\Property;
use App\Services\References\ReferenceService;
use App\Support\Departures\SeedMapper as DepartureSeedMapper;
use App\Support\Itineraries\Gradients;
use App\Support\Itineraries\SeedMapper;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;

test('the seeded itineraries match seed-data.json via the key map', function (): void {
    $this->seed(InventorySeeder::class);
    $this->seed(DemoInventorySeeder::class);
    $this->seed(DemoInventorySeeder::class);

    $path = base_path('docs/requirements/examples/seed-data.json');
    /** @var array{itineraries: list<array<string, mixed>>} $seed */
    $seed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect(Itinerary::query()->count())->toBe(3);

    foreach ($seed['itineraries'] as $row) {
        $mapped = SeedMapper::fromPrototype($row);
        $itinerary = Itinerary::query()->where('code', $mapped['code'])->firstOrFail();

        expect($mapped['code'])->toBe($row['k']);
        expect($mapped['sort_order'])->toBe($row['order']);
        expect($mapped['days'])->toBe($row['nDays']);
        expect($mapped['tagline'])->toBe($row['tag']);
        expect($mapped['fallback_gradient'])->toBe(Gradients::keyFromCss((string) $row['grad']));
        expect($mapped['hero_image_path'])->toBeNull();
        expect($mapped['hero_alt'])->toBe($row['alt']);
        expect($mapped['card_description'])->toBe($row['desc']);
        expect($mapped['long_description'])->toBe($row['long']);
        expect($mapped['highlights'])->toBe($row['hi']);
        expect($mapped['day_plan'])->toBe($row['plan']);
        expect($mapped['included'])->toBe($row['inc']);
        expect($mapped['excluded'])->toBe($row['exc']);
        expect($mapped['meta_title'])->toBe($row['metaT']);
        expect($mapped['meta_description'])->toBe($row['metaD']);

        expect($itinerary->code)->toBe($mapped['code']);
        expect($itinerary->name)->toBe($mapped['name']);
        expect($itinerary->status)->toBe(ItineraryStatus::Published);
        expect($itinerary->sort_order)->toBe($mapped['sort_order']);
        expect($itinerary->festive)->toBe($mapped['festive']);
        expect($itinerary->days)->toBe($mapped['days']);
        expect($itinerary->nights)->toBe($mapped['nights']);
        expect($itinerary->embark)->toBe($mapped['embark']);
        expect($itinerary->disembark)->toBe($mapped['disembark']);
        expect($itinerary->tagline)->toBe($mapped['tagline']);
        expect($itinerary->hero_image_path)->toBeNull();
        expect($itinerary->fallback_gradient)->toBe($mapped['fallback_gradient']);
        expect($itinerary->card_description)->toBe($mapped['card_description']);
        expect($itinerary->highlights)->toBe($mapped['highlights']);
        expect($itinerary->day_plan)->toBe($mapped['day_plan']);
        expect($itinerary->slug)->toBe($mapped['slug']);
    }

    expect(Itinerary::query()->where('status', ItineraryStatus::Published)->count())->toBe(3);
});

test('the seeded departures match seed-data.json via the key map and stay at 16', function (): void {
    $this->seed(InventorySeeder::class);
    $this->seed(DemoInventorySeeder::class);
    $this->seed(DemoInventorySeeder::class);

    $path = base_path('docs/requirements/examples/seed-data.json');
    /** @var array{departures: list<array<string, mixed>>} $seed */
    $seed = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect(Departure::query()->count())->toBe(16);
    expect(Departure::query()->min('date'))->toBe('2027-11-07');
    expect(Departure::query()->max('date'))->toBe('2027-12-26');

    foreach ($seed['departures'] as $row) {
        $mapped = DepartureSeedMapper::fromPrototype($row);
        $property = Property::query()->where('code', $mapped['property_code'])->firstOrFail();
        $itinerary = Itinerary::query()->where('code', $mapped['itinerary_code'])->firstOrFail();
        $departure = Departure::query()
            ->where('property_id', $property->id)
            ->whereDate('date', $mapped['date'])
            ->firstOrFail();

        expect($mapped['reference'])->toBe($row['id']);
        expect($mapped['property_code'])->toBe($row['property']);
        expect($mapped['itinerary_code'])->toBe($row['itin']);
        expect($mapped['urgency_threshold'])->toBe($row['thr']);
        expect($mapped['waitlist_enabled'])->toBe($row['wait']);
        expect($mapped['date'])->toBe($row['date']);
        expect($mapped['festive'])->toBe($row['festive']);
        expect($row)->toHaveKey('di');

        expect($departure->reference)->toBe($mapped['reference']);
        expect($departure->itinerary_id)->toBe($itinerary->id);
        expect($departure->status)->toBe($mapped['status']);
        expect($departure->urgency_threshold)->toBe($mapped['urgency_threshold']);
        expect($departure->waitlist_enabled)->toBe($mapped['waitlist_enabled']);
        expect($departure->public_note)->toBe($mapped['public_note']);
        expect($departure->festive)->toBe($mapped['festive']);
        expect($departure->date->toDateString())->toBe($mapped['date']);
    }

    $next = DB::transaction(fn (): string => app(ReferenceService::class)->next(ReferenceType::Departure));
    expect($next)->toBe('DEP-017');
});

test('the next departure created after the demo seed is DEP-017', function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(DemoInventorySeeder::class);

    $west = Itinerary::query()->where('code', 'WEST')->firstOrFail();
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $mateo = managerUser();

    $this->actingAs($mateo)
        ->postJson('/api/rms/departures', [
            'date' => '2028-04-02',
            'property_id' => $property->id,
            'itinerary_id' => $west->id,
            'status' => DepartureStatus::OnSale->value,
        ])
        ->assertCreated()
        ->assertJsonPath('reference', 'DEP-017');
});

test('the demo seed creates BLK-001 on ANAMARA S7-S8 14 Nov 2027 and the next block is BLK-002', function (): void {
    $this->seed(InventorySeeder::class);
    $this->seed(DemoInventorySeeder::class);
    $this->seed(DemoInventorySeeder::class);

    $block = InternalBlock::query()->where('reference', 'BLK-001')->firstOrFail();
    $departure = Departure::query()->where('reference', 'DEP-003')->firstOrFail();

    expect($block->property_id)->toBe($departure->property_id);
    expect($block->starts_on->toDateString())->toBe($departure->date->toDateString());
    expect($block->ends_on->toDateString())->toBe($departure->stayDates()->checkOut()->toDateString());
    expect($block->reason)->toBe(BlockReason::FamTrip);
    expect($block->notes)->toBe('Virtuoso agents fam — 4 pax');
    expect($block->created_by)->toBeNull();
    expect($block->claims()->whereNull('released_at')->pluck('room_id')->unique())->toHaveCount(2);
    expect($block->claims()->whereNull('released_at')->pluck('kind')->unique()->all())->toBe([ClaimKind::Block]);
    expect($block->claims()->whereNull('released_at')->orderBy('night')->first()?->night->toDateString())->toBe($departure->date->toDateString());
    expect($block->claims()->with('room')->get()->pluck('room.code')->unique()->sort()->values()->all())->toBe(['S7', 'S8']);

    $next = DB::transaction(fn (): string => app(ReferenceService::class)->next(ReferenceType::Block));
    expect($next)->toBe('BLK-002');
});
