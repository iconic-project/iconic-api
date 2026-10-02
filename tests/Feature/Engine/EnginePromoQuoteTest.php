<?php

declare(strict_types=1);

use App\Enums\CabinCategory;
use App\Enums\DepartureStatus;
use App\Enums\OfferChannel;
use App\Enums\OfferType;
use App\Models\Departure;
use App\Models\Offer;
use App\Support\IpHash;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\InventorySeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Support\Bookings\ReservationFixtures;
use Tests\Support\Offers\OfferFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
    $this->seed(InventorySeeder::class);
    $this->seed(ConfigSeeder::class);
    Cache::flush();
});

function engineWestDeparture(string $date = '2027-11-07', bool $festive = false): Departure
{
    $departure = ReservationFixtures::anamaraDeparture($date, $festive);
    $departure->update([
        'itinerary_id' => OfferFixtures::west()->id,
        'festive' => $festive,
        'status' => DepartureStatus::OnSale,
    ]);

    return $departure->fresh(['yacht.cabins', 'itinerary']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function engineLivePercent(array $overrides = []): Offer
{
    return Offer::factory()->live()->create(array_merge([
        'type' => OfferType::Percent,
        'value' => 12,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => true,
        'is_promo_code' => false,
        'price_line' => 'Last cabins −12%',
        'name' => 'Last cabins',
        'code' => 'LAST12',
        'badge' => 'LAST',
        'show_on_card' => true,
        'show_on_departures' => true,
    ], $overrides));
}

function engineIconic10(): Offer
{
    return Offer::factory()->live()->promo()->create([
        'code' => 'ICONIC10',
        'name' => 'Iconic welcome',
        'type' => OfferType::Percent,
        'value' => 10,
        'channel' => OfferChannel::D2C,
        'cabin_types' => [CabinCategory::Suite->value],
        'itinerary_codes' => ['WEST'],
        'combinable' => true,
        'price_line' => 'Iconic welcome −10%',
    ]);
}

/**
 * @param  list<array{cabin_code: string, adults: int, children: int}>  $cabins
 * @return array<string, mixed>
 */
function promoPayload(Departure $departure, string $code, array $cabins): array
{
    $adults = array_sum(array_column($cabins, 'adults'));
    $children = array_sum(array_column($cabins, 'children'));

    return [
        'code' => $code,
        'departure_id' => $departure->id,
        'cabins' => $cabins,
        'guests' => ['adults' => $adults, 'children' => $children],
    ];
}

test('a valid promo returns the line and applies_to from the quote', function (): void {
    $departure = engineWestDeparture();
    engineLivePercent();
    engineIconic10();

    $this->postJson('/api/engine/promo/check', promoPayload($departure, 'ICONIC10', [
        ['cabin_code' => 'Suite 01', 'adults' => 2, 'children' => 0],
    ]))
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('reason', null)
        ->assertJsonPath('line', 'Iconic welcome −10%')
        ->assertJsonPath('applies_to', ['Suite 01']);
});

test('a suite-only code on suite plus owner is valid only for the suite', function (): void {
    $departure = engineWestDeparture();
    engineIconic10();

    $this->postJson('/api/engine/promo/check', promoPayload($departure, 'ICONIC10', [
        ['cabin_code' => 'Suite 01', 'adults' => 2, 'children' => 0],
        ['cabin_code' => "Owner's Suite", 'adults' => 2, 'children' => 0],
    ]))
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('applies_to', ['Suite 01']);

    $quote = $this->postJson('/api/engine/quote', [
        'departure_id' => $departure->id,
        'cabins' => [
            ['cabin_code' => 'Suite 01', 'adults' => 2, 'children' => 0],
            ['cabin_code' => "Owner's Suite", 'adults' => 2, 'children' => 0],
        ],
        'promo_code' => 'ICONIC10',
    ])->assertOk()->json();

    $suiteLines = collect($quote['cabins'][0]['quote']['lines'] ?? [])->pluck('code');
    $ownerLines = collect($quote['cabins'][1]['quote']['lines'] ?? [])->pluck('code');

    expect($suiteLines)->toContain('ICONIC10');
    expect($ownerLines)->not->toContain('ICONIC10');
});

test('an unknown code and a festive departure use the prototype reasons', function (): void {
    $departure = engineWestDeparture();
    $festive = engineWestDeparture('2027-12-19', true);
    engineIconic10();

    Log::spy();

    $this->postJson('/api/engine/promo/check', promoPayload($departure, 'NOPE', [
        ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
    ]))
        ->assertOk()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('reason', 'This code is not valid')
        ->assertJsonPath('line', null)
        ->assertJsonPath('applies_to', []);

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($departure): bool {
        return $message === 'engine.promo.invalid'
            && ($context['ip_hash'] ?? null) === IpHash::of('127.0.0.1')
            && ($context['departure_id'] ?? null) === $departure->id
            && ($context['reason'] ?? null) === 'This code is not valid'
            && ! array_key_exists('code', $context)
            && ! array_key_exists('ip', $context)
            && ! in_array('NOPE', $context, true);
    });

    $this->postJson('/api/engine/promo/check', promoPayload($festive, 'ICONIC10', [
        ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
    ]))
        ->assertOk()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('reason', 'This code does not apply to festive departures');
});

test('the engine quote matches the walkthrough totals', function (): void {
    $departure = engineWestDeparture();
    engineLivePercent();
    engineIconic10();

    $later = $this->postJson('/api/engine/quote', [
        'departure_id' => $departure->id,
        'cabins' => [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]],
        'promo_code' => 'ICONIC10',
    ])->assertOk()->json();

    expect($later['total'])->toBe(21067);
    expect($later['deposit'])->toBe(2107);

    $online = $this->postJson('/api/engine/quote', [
        'departure_id' => $departure->id,
        'cabins' => [['cabin_code' => 'Suite 01', 'adults' => 2, 'children' => 0]],
        'promo_code' => 'ICONIC10',
        'online_deposit' => true,
    ])->assertOk()->json();

    expect($online['total'])->toBe(20014);
    expect($online['deposit'])->toBe(2001);
});

test('promo checks are rate limited to ten per minute', function (): void {
    $departure = engineWestDeparture();
    $payload = promoPayload($departure, 'NOPE', [
        ['cabin_code' => 'S1', 'adults' => 2, 'children' => 0],
    ]);

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/engine/promo/check', $payload)->assertOk();
    }

    $this->postJson('/api/engine/promo/check', $payload)->assertStatus(429);
});

test('feed reads are rate limited to sixty per minute', function (): void {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/engine/feed')->assertOk();
    }

    $this->getJson('/api/engine/feed')->assertStatus(429);
});

test('a hidden departure cannot be quoted', function (): void {
    $departure = engineWestDeparture();
    $departure->update(['status' => DepartureStatus::Hidden]);

    $this->postJson('/api/engine/quote', [
        'departure_id' => $departure->id,
        'cabins' => [['cabin_code' => 'S1', 'adults' => 2, 'children' => 0]],
    ])->assertNotFound();
});
