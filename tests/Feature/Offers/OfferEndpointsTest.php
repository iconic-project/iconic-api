<?php

declare(strict_types=1);

use App\Models\Offer;
use Database\Seeders\RolesSeeder;
use Tests\Support\Offers\OfferFixtures;

beforeEach(function (): void {
    $this->seed(RolesSeeder::class);
});

test('a sales exec can read offers and cannot write', function (): void {
    $offer = Offer::factory()->create();

    $this->actingAs(salesExecUser())
        ->getJson('/api/rms/offers')
        ->assertOk()
        ->assertJsonPath('data.0.code', $offer->code);

    $this->actingAs(salesExecUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload())
        ->assertForbidden();
});

test('index filters by channel query and date span', function (): void {
    Offer::factory()->create([
        'code' => 'OPENING-27',
        'name' => 'Opening season credit',
        'channel' => 'D2C',
        'stay_from' => '2027-11-01',
        'stay_to' => '2027-12-31',
    ]);
    Offer::factory()->create([
        'code' => 'VIRTUOSO-EARLY',
        'name' => 'Virtuoso early booking',
        'channel' => 'B2B',
        'booking_from' => '2027-01-01',
        'booking_to' => '2027-03-31',
    ]);

    $byChannel = $this->actingAs(managerUser())
        ->getJson('/api/rms/offers?channel=B2B')
        ->assertOk()
        ->json('data');

    expect(collect($byChannel)->pluck('code')->all())->toBe(['VIRTUOSO-EARLY']);

    $byQuery = $this->actingAs(managerUser())
        ->getJson('/api/rms/offers?q=OPENING')
        ->assertOk()
        ->json('data');

    expect(collect($byQuery)->pluck('code')->all())->toBe(['OPENING-27']);

    $bySpan = $this->actingAs(managerUser())
        ->getJson('/api/rms/offers?from=2027-11-01&to=2027-11-30')
        ->assertOk()
        ->json('data');

    expect(collect($bySpan)->pluck('code')->all())->toBe(['OPENING-27']);
});

test('show returns prototype derived columns', function (): void {
    $offer = Offer::factory()->live()->create([
        'code' => 'OPENING-27',
        'name' => 'Opening season credit',
        'type' => 'CREDIT',
        'value' => 500,
        'badge' => 'OPENING OFFER',
        'show_on_card' => true,
        'show_on_calendar' => true,
    ]);

    $this->actingAs(managerUser())
        ->getJson('/api/rms/offers/'.$offer->id)
        ->assertOk()
        ->assertJsonPath('benefit_label', 'USD 500 ancillary credit per room')
        ->assertJsonPath('booking_window_label', 'Any')
        ->assertJsonPath('stay_window_label', 'Any')
        ->assertJsonPath('engine_placement', 'badge');
});

test('derived labels match the prototype wording', function (): void {
    $offer = Offer::factory()->live()->create([
        'type' => 'PCT',
        'value' => 10,
        'channel' => 'ALL',
        'booking_from' => '2027-01-01',
        'booking_to' => '2027-03-31',
    ]);

    $this->actingAs(managerUser())
        ->getJson('/api/rms/offers/'.$offer->id)
        ->assertOk()
        ->assertJsonPath('benefit_label', '10% off room rate')
        ->assertJsonPath('scope_label', 'All channels · All room types · All rate plans')
        ->assertJsonPath('booking_window_label', '1 Jan 2027 → 31 Mar 2027');
});

test('offer history is listed', function (): void {
    $created = $this->actingAs(managerUser())
        ->postJson('/api/rms/offers', OfferFixtures::payload(['as_draft' => true]))
        ->assertCreated()
        ->json();

    $this->actingAs(managerUser())
        ->getJson('/api/rms/offers/'.$created['id'].'/history')
        ->assertOk()
        ->assertJsonPath('data.0.event', 'offer.created');

    $this->actingAs(salesExecUser())
        ->getJson('/api/rms/offers/'.$created['id'].'/history')
        ->assertOk();
});
