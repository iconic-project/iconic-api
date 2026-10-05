<?php

declare(strict_types=1);

use App\Actions\Offers\PauseOffer;
use App\Enums\CabinCategory;
use App\Enums\ClaimKind;
use App\Enums\DepartureStatus;
use App\Enums\HoldType;
use App\Enums\ItineraryStatus;
use App\Enums\OfferChannel;
use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Models\Departure;
use App\Models\Itinerary;
use App\Models\Offer;
use App\Models\Property;
use App\Services\Engine\EngineFeedVersion;
use App\Services\Inventory\ClaimService;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Inventory\ClaimHolder;
use Tests\Support\Offers\OfferFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Cache::flush();
});

/**
 * @return list<string>
 */
function engineKeys(mixed $payload): array
{
    if (! is_array($payload)) {
        return [];
    }

    $keys = [];

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...engineKeys($value)];
    }

    return $keys;
}

test('the feed excludes drafts, hidden departures, paused pending b2b and promo offers', function (): void {
    $this->seed(DemoInventorySeeder::class);

    $draft = Itinerary::factory()->create([
        'code' => 'DRAFTLEAK',
        'name' => 'Draft Leak Itinerary',
        'status' => ItineraryStatus::Draft,
    ]);

    $hidden = Departure::factory()->create([
        'reference' => 'DEP-HIDDEN-LEAK',
        'property_id' => Property::query()->where('code', 'ANAMARA')->value('id'),
        'itinerary_id' => OfferFixtures::west()->id,
        'status' => DepartureStatus::Hidden,
        'date' => '2028-05-07',
    ]);

    Offer::factory()->paused()->create([
        'code' => 'PAUSELEAK',
        'channel' => OfferChannel::D2C,
        'itinerary_codes' => ['WEST'],
        'badge' => 'PAUSED',
        'show_on_card' => true,
        'show_on_departures' => true,
    ]);

    Offer::factory()->pending()->create([
        'code' => 'PENDLEAK',
        'channel' => OfferChannel::D2C,
        'itinerary_codes' => ['WEST'],
        'badge' => 'PENDING',
        'show_on_card' => true,
        'show_on_departures' => true,
    ]);

    Offer::factory()->live()->create([
        'code' => 'B2BLEAK',
        'channel' => OfferChannel::B2B,
        'itinerary_codes' => ['WEST'],
        'is_promo_code' => false,
    ]);

    Offer::factory()->live()->promo()->create([
        'code' => 'PROMOLEAK',
        'channel' => OfferChannel::D2C,
        'itinerary_codes' => ['WEST'],
    ]);

    Offer::factory()->live()->create([
        'code' => 'OPENING-27',
        'type' => OfferType::Credit,
        'value' => 500,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST', 'NORTH'],
        'travel_from' => '2027-11-01',
        'travel_to' => '2027-12-31',
        'badge' => 'OPENING OFFER',
        'show_on_card' => true,
        'show_on_departures' => true,
        'price_line' => 'Opening season credit',
    ]);

    $json = $this->getJson('/api/engine/feed')->assertOk()->json();
    $encoded = (string) json_encode($json);

    expect($encoded)->not->toContain('DRAFTLEAK');
    expect($encoded)->not->toContain('Draft Leak Itinerary');
    expect($encoded)->not->toContain('DEP-HIDDEN-LEAK');
    expect($encoded)->not->toContain('PAUSELEAK');
    expect($encoded)->not->toContain('PENDLEAK');
    expect($encoded)->not->toContain('B2BLEAK');
    expect($encoded)->not->toContain('PROMOLEAK');
    expect($encoded)->not->toContain('ICONIC10');
    expect($json['offers'][0]['code'] ?? null)->toBe('OPENING-27');
    expect($json['settings']['policies'])->not->toHaveKey('max_commission_pct');
    expect($json['settings']['policies'])->not->toHaveKey('cancellation_bands');

    $keys = engineKeys($json);
    expect($keys)->not->toContain('max_commission_pct');
    expect($keys)->not->toContain('cancellation_bands');
    expect($keys)->not->toContain('min_days');
    expect($keys)->not->toContain('penalty_pct');

    foreach (['holder', 'reference', 'email', 'phone', 'passport', 'owner'] as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }

    expect($hidden->id)->not->toBeIn(array_column($json['departures'], 'id'));
});

test('engine labels follow availability including LIMITED AVAILABILITY', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $itinerary = OfferFixtures::west();

    $available = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-02',
        'status' => DepartureStatus::OnSale,
        'urgency_threshold' => 3,
        'waitlist_enabled' => true,
    ]);

    $urgent = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-09',
        'status' => DepartureStatus::OnSale,
        'urgency_threshold' => 3,
        'waitlist_enabled' => true,
    ]);

    $limited = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-16',
        'status' => DepartureStatus::OnSale,
        'waitlist_enabled' => true,
    ]);

    $full = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => $itinerary->id,
        'date' => '2028-04-23',
        'status' => DepartureStatus::OnSale,
        'waitlist_enabled' => true,
    ]);

    $holder = ClaimHolder::query()->create(['reference' => 'HLD-ENG', 'name' => 'Engine']);
    $other = ClaimHolder::query()->create(['reference' => 'HLD-ENG-2', 'name' => 'Other']);

    DB::transaction(function () use ($urgent, $limited, $full, $property, $holder, $other): void {
        $service = app(ClaimService::class);
        $service->claim($urgent->stayDates(), $property->cabins->take(6), $holder, ClaimKind::Booking);
        $service->claim($limited->stayDates(), $property->cabins, $holder, ClaimKind::Hold, HoldType::Agency, now()->addDay());
        $service->claim($full->stayDates(), $property->cabins->take(8), $holder, ClaimKind::Booking);
        $service->claim($full->stayDates(), $property->cabins->slice(8), $other, ClaimKind::Booking);
    });

    $feed = $this->getJson('/api/engine/feed')->assertOk()->json('departures');
    $byId = collect($feed)->keyBy('id');

    expect($byId[$available->id]['label'])->toBe('AVAILABLE');
    expect($byId[$urgent->id]['label'])->toBe('ONLY 3 CABINS LEFT');
    expect($byId[$limited->id]['label'])->toBe('LIMITED AVAILABILITY');
    expect($byId[$full->id]['label'])->toBe('FULL · WAITLIST');
});

test('a claim updates the next feed and cabins response', function (): void {
    $property = Property::query()->where('code', 'ANAMARA')->firstOrFail();
    $departure = Departure::factory()->create([
        'property_id' => $property->id,
        'itinerary_id' => OfferFixtures::west()->id,
        'date' => '2028-04-02',
        'status' => DepartureStatus::OnSale,
    ]);

    $version = EngineFeedVersion::current();
    $before = $this->getJson('/api/engine/feed')->assertOk()->json();
    $row = collect($before['departures'])->firstWhere('id', $departure->id);
    expect($row['suites_free'])->toBe(8);
    expect($row['owner_free'])->toBeTrue();

    $cabins = $this->getJson('/api/engine/departures/'.$departure->id.'/cabins')->assertOk()->json();
    expect($cabins[0])->toHaveKeys(['code', 'category', 'bookable']);
    expect($cabins[0]['code'])->toBe('Suite 01');
    expect(engineKeys($cabins))->not->toContain('holder');
    expect(engineKeys($cabins))->not->toContain('reference');

    $holder = ClaimHolder::query()->create(['reference' => 'HLD-S1', 'name' => 'Taken']);
    $suite = $property->cabins->firstWhere('code', 'S1');

    DB::transaction(function () use ($departure, $suite, $holder): void {
        app(ClaimService::class)->claim($departure->stayDates(), collect([$suite]), $holder, ClaimKind::Booking);
    });

    expect(EngineFeedVersion::current())->toBeGreaterThan($version);

    $after = $this->getJson('/api/engine/feed')->assertOk()->json();
    $updated = collect($after['departures'])->firstWhere('id', $departure->id);
    expect($updated['suites_free'])->toBe(7);

    $cabinsAfter = $this->getJson('/api/engine/departures/'.$departure->id.'/cabins')->assertOk()->json();
    $suite01 = collect($cabinsAfter)->firstWhere('code', 'Suite 01');
    expect($suite01['bookable'])->toBeFalse();
});

test('pausing an offer removes it from the next feed and bumps the version', function (): void {
    $offer = Offer::factory()->live()->create([
        'code' => 'LIVEBADGE',
        'channel' => OfferChannel::D2C,
        'itinerary_codes' => ['WEST'],
        'cabin_types' => [CabinCategory::Suite->value],
        'badge' => 'LIVE',
        'show_on_card' => true,
        'show_on_departures' => true,
    ]);

    $this->getJson('/api/engine/feed')->assertOk();
    $version = EngineFeedVersion::current();
    $codes = collect($this->getJson('/api/engine/feed')->json('offers'))->pluck('code');
    expect($codes)->toContain('LIVEBADGE');

    app(PauseOffer::class)->handle($offer, adminUser());

    expect(EngineFeedVersion::current())->toBeGreaterThan($version);
    expect(collect($this->getJson('/api/engine/feed')->json('offers'))->pluck('code'))->not->toContain('LIVEBADGE');
    expect($offer->fresh()->status)->toBe(OfferStatus::Paused);
});

test('etag is stable when nothing changed and returns 304', function (): void {
    $this->seed(DemoInventorySeeder::class);

    $first = $this->getJson('/api/engine/feed')->assertOk();
    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeEmpty();
    expect($first->headers->get('Cache-Control'))->toContain('max-age=15');
    expect($first->headers->get('Cache-Control'))->toContain('stale-while-revalidate=15');

    $second = $this->getJson('/api/engine/feed')->assertOk();
    expect($second->headers->get('ETag'))->toBe($etag);

    $this->withHeaders(['If-None-Match' => $etag])
        ->getJson('/api/engine/feed')
        ->assertStatus(304);
});

test('the feed query count stays flat as departures are added', function (): void {
    $this->seed(DemoInventorySeeder::class);
    EngineFeedVersion::bump();
    $this->getJson('/api/engine/feed')->assertOk();
    EngineFeedVersion::bump();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson('/api/engine/feed')->assertOk();
    $first = count(DB::getQueryLog());
    DB::disableQueryLog();

    $property = Property::query()->where('code', 'ANATIVA')->firstOrFail();
    $itinerary = OfferFixtures::west();

    foreach (['2028-06-04', '2028-06-11', '2028-06-18', '2028-06-25'] as $date) {
        Departure::factory()->create([
            'property_id' => $property->id,
            'itinerary_id' => $itinerary->id,
            'date' => $date,
            'status' => DepartureStatus::OnSale,
        ]);
    }

    EngineFeedVersion::bump();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson('/api/engine/feed')->assertOk();
    $second = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($second)->toBe($first);
});

test('hidden departures are 404 on the cabins endpoint', function (): void {
    $departure = Departure::factory()->create([
        'property_id' => Property::query()->where('code', 'ANAMARA')->value('id'),
        'itinerary_id' => OfferFixtures::west()->id,
        'status' => DepartureStatus::Hidden,
        'date' => '2028-05-07',
    ]);

    $this->getJson('/api/engine/departures/'.$departure->id.'/cabins')->assertNotFound();
});

test('the public settings include extras due hours and consent versions', function (): void {
    $json = $this->getJson('/api/engine/feed')->assertOk()->json();

    expect($json['settings']['policies']['extras_due_hours'])->toBe(72);
    expect($json['settings']['legal']['consent_versions'])->toHaveKeys([
        'terms',
        'cancellation',
        'privacy',
        'insurance',
        'marketing',
        'analytics',
        'checkout_marketing',
    ]);
    expect($json['settings']['legal']['consent_versions']['privacy'])->not->toBe('');
});
